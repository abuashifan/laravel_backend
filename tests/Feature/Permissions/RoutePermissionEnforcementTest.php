<?php

namespace Tests\Feature\Permissions;

use App\Shared\Models\CompanyUser;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Fase 7 hak akses per pengguna: setiap rute API yang dijaga `permission:`
 * benar-benar memakai kunci matriks yang bisa dicentang di layar Akses, dan
 * middleware-nya menolak tanpa kunci itu serta meloloskan dengan kunci itu.
 *
 * Penolakan middleware dikenali dari `meta.permission` pada respons 403,
 * sehingga 403 lain dari controller (mis. pengaman pemilik) tidak dihitung.
 */
class RoutePermissionEnforcementTest extends JournalTestCase
{
    /**
     * @return list<array{route: LaravelRoute, permission: string, keys: list<string>}>
     */
    private function guardedRoutes(): array
    {
        $guarded = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'permission:')) {
                    $permission = substr($middleware, strlen('permission:'));
                    $guarded[] = [
                        'route' => $route,
                        'permission' => $permission,
                        'keys' => array_values(array_filter(explode('|', $permission))),
                    ];
                }
            }
        }

        return $guarded;
    }

    private function method(LaravelRoute $route): string
    {
        return collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD');
    }

    private function uri(LaravelRoute $route): string
    {
        return '/'.preg_replace('/\{[^}]+\}/', '999999', $route->uri());
    }

    public function test_every_guarded_route_uses_keys_grantable_from_the_matrix(): void
    {
        $matrix = (array) config('permissions.matrix_keys');
        $retired = (array) config('permissions.retired_keys');
        $problems = [];

        foreach ($this->guardedRoutes() as $item) {
            $label = $this->method($item['route']).' '.$item['route']->uri().' ['.$item['permission'].']';
            if (array_intersect($item['keys'], $matrix) === []) {
                $problems[] = 'tidak ada kunci matriks: '.$label;
            }
            foreach (array_intersect($item['keys'], $retired) as $key) {
                $problems[] = "kunci pensiun {$key}: ".$label;
            }
        }

        $this->assertNotEmpty($this->guardedRoutes(), 'Tidak ada rute berizin yang terbaca.');
        $this->assertSame([], $problems, "Rute dengan izin yang tidak bisa diberikan lewat layar Akses:\n".implode("\n", $problems));
    }

    public function test_every_guarded_route_denies_without_its_key_and_passes_with_it(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->enforcePlanFeatures(false);
        $membership = CompanyUser::query()
            ->where('company_id', $ctx['company']->id)
            ->where('user_id', $ctx['user']->id)
            ->firstOrFail();
        $this->assignTestPermissions($membership, []);

        $notDenied = [];
        $stillDenied = [];

        foreach ($this->guardedRoutes() as $item) {
            $method = $this->method($item['route']);
            $uri = $this->uri($item['route']);
            $label = "{$method} {$item['route']->uri()} [{$item['permission']}]";

            // Tanpa izin apa pun: middleware wajib menolak dengan kunci rute ini.
            $this->assignTestPermissions($membership, []);
            $denied = $this->json($method, $uri, [], $ctx['headers']);
            if ($denied->status() !== 403 || $denied->json('meta.permission') !== $item['permission']) {
                $notDenied[] = $label.' -> '.$denied->status();
            }

            // Hanya kunci pertama rute ini: middleware izin tidak boleh menolak lagi.
            $this->assignTestPermissions($membership, [$item['keys'][0]]);
            $allowed = $this->json($method, $uri, [], $ctx['headers']);
            if ($allowed->status() === 403 && $allowed->json('meta.permission') === $item['permission']) {
                $stillDenied[] = $label;
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
            $response = $this->json($this->method($item['route']), $this->uri($item['route']), [], $ctx['headers']);
            if ($response->status() === 403 && $response->json('meta.permission') === $item['permission']) {
                $denied[] = $item['route']->uri().' ['.$item['permission'].']';
            }
        }

        $this->assertSame([], $denied, "Akses penuh ditolak middleware di:\n".implode("\n", $denied));
    }
}
