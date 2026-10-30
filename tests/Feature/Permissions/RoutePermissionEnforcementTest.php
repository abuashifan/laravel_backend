<?php

namespace Tests\Feature\Permissions;

use App\Shared\Models\CompanyUser;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Fase 7 hak akses per pengguna: setiap rute API yang dijaga `permission:`
 * benar-benar memakai kunci matriks yang bisa dicentang di layar Akses, dan
 * middleware-nya menolak tanpa kunci itu serta meloloskan dengan kunci itu.
 *
 * Satu rute bisa dijaga beberapa lapis (mis. grup `reports.tax.view` + rute
 * `reports.tax.export`); semua lapis diperlakukan sebagai satu kesatuan.
 * Penolakan middleware dikenali dari `meta.permission` pada respons 403,
 * sehingga 403 lain dari controller (mis. pengaman pemilik) tidak dihitung.
 */
class RoutePermissionEnforcementTest extends JournalTestCase
{
    /**
     * @return list<array{route: LaravelRoute, guards: list<string>}>
     */
    private function guardedRoutes(): array
    {
        $guarded = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            $guards = [];
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $guards[] = substr($middleware, strlen('permission:'));
                }
            }
            if ($guards !== []) {
                $guarded[] = ['route' => $route, 'guards' => array_values(array_unique($guards))];
            }
        }

        return $guarded;
    }

    /** @return list<string> */
    private function keys(string $guard): array
    {
        return array_values(array_filter(explode('|', $guard)));
    }

    private function method(LaravelRoute $route): string
    {
        return collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD');
    }

    /**
     * Parameter diisi nilai yang cocok dengan batasan `where` rute (mis.
     * jenis dokumen pada riwayat edit); tanpa batasan dipakai id fiktif.
     */
    private function uri(LaravelRoute $route): string
    {
        return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', function (array $m) use ($route): string {
            $pattern = $route->wheres[$m[1]] ?? null;
            if (! is_string($pattern)) {
                return '999999';
            }
            foreach (['999999', 'sales_invoice', 'x', '00000000-0000-4000-8000-000000000000'] as $candidate) {
                if (preg_match('#^(?:'.$pattern.')$#', $candidate) === 1) {
                    return $candidate;
                }
            }

            // Pola berupa daftar nilai (a|b|c): pakai nilai pertama.
            return trim(explode('|', $pattern)[0], '()^$');
        }, $route->uri());
    }

    private function label(array $item): string
    {
        return $this->method($item['route']).' '.$item['route']->uri().' ['.implode(' + ', $item['guards']).']';
    }

    /**
     * Status + `meta.permission` dari respons. Rute unduhan (ekspor/templat)
     * mengembalikan StreamedResponse yang tidak punya `status()`/`json()`.
     *
     * @return array{0: int, 1: ?string}
     */
    private function outcome(TestResponse $response): array
    {
        $status = $response->baseResponse->getStatusCode();

        return [$status, $status === 403 ? $response->json('meta.permission') : null];
    }

    public function test_every_guarded_route_uses_keys_grantable_from_the_matrix(): void
    {
        $matrix = (array) config('permissions.matrix_keys');
        $retired = (array) config('permissions.retired_keys');
        $problems = [];

        $routes = $this->guardedRoutes();
        foreach ($routes as $item) {
            foreach ($item['guards'] as $guard) {
                if (array_intersect($this->keys($guard), $matrix) === []) {
                    $problems[] = "tidak ada kunci matriks di [{$guard}]: ".$this->label($item);
                }
                foreach (array_intersect($this->keys($guard), $retired) as $key) {
                    $problems[] = "kunci pensiun {$key}: ".$this->label($item);
                }
            }
        }

        $this->assertNotEmpty($routes, 'Tidak ada rute berizin yang terbaca.');
        $this->assertSame([], $problems, "Rute dengan izin yang tidak bisa diberikan lewat layar Akses:\n".implode("\n", $problems));
    }

    public function test_every_guarded_route_denies_without_its_keys_and_passes_with_them(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->enforcePlanFeatures(false);
        $membership = CompanyUser::query()
            ->where('company_id', $ctx['company']->id)
            ->where('user_id', $ctx['user']->id)
            ->firstOrFail();

        $notDenied = [];
        $stillDenied = [];

        foreach ($this->guardedRoutes() as $item) {
            $method = $this->method($item['route']);
            $uri = $this->uri($item['route']);

            // Tanpa izin apa pun: salah satu lapis izin rute ini wajib menolak.
            $this->assignTestPermissions($membership, []);
            [$status, $deniedBy] = $this->outcome($this->json($method, $uri, [], $ctx['headers']));
            if ($status !== 403 || ! in_array($deniedBy, $item['guards'], true)) {
                $notDenied[] = $this->label($item).' -> '.$status;
            }

            // Kunci pertama dari setiap lapis: tidak ada lapis izin yang boleh menolak lagi.
            $this->assignTestPermissions($membership, array_map(fn (string $guard): string => $this->keys($guard)[0], $item['guards']));
            [, $deniedBy] = $this->outcome($this->json($method, $uri, [], $ctx['headers']));
            if (in_array($deniedBy, $item['guards'], true)) {
                $stillDenied[] = $this->label($item).' ditolak oleh ['.$deniedBy.']';
            }
        }

        $this->assertSame([], $notDenied, "Rute yang tidak ditolak tanpa izin:\n".implode("\n", $notDenied));
        $this->assertSame([], $stillDenied, "Rute yang tetap ditolak walau kuncinya diberikan:\n".implode("\n", $stillDenied));
    }

    public function test_full_access_passes_every_permission_middleware(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->enforcePlanFeatures(false);
        $denied = [];

        foreach ($this->guardedRoutes() as $item) {
            [, $deniedBy] = $this->outcome($this->json($this->method($item['route']), $this->uri($item['route']), [], $ctx['headers']));
            if (in_array($deniedBy, $item['guards'], true)) {
                $denied[] = $this->label($item);
            }
        }

        $this->assertSame([], $denied, "Akses penuh ditolak middleware di:\n".implode("\n", $denied));
    }
}
