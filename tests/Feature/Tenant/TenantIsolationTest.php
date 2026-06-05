<?php

namespace Tests\Feature\Tenant;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private array $tenantFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tenantFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    public function test_unauthenticated_user_cannot_access_tenant_context(): void
    {
        $this->getJson('/api/tenant-context-test')
            ->assertStatus(401);
    }

    public function test_authenticated_user_cannot_access_tenant_context_without_x_company_id(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user);

        $this->getJson('/api/tenant-context-test')
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_authenticated_user_can_access_assigned_company_tenant_context(): void
    {
        [$userA, $companyA] = $this->seedTenantForUser(role: 'owner');

        Sanctum::actingAs($userA);

        $this->getJson('/api/tenant-context-test', ['X-Company-ID' => (string) $companyA->id])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.company_id', $companyA->id);
    }

    public function test_authenticated_user_cannot_access_another_users_company_tenant_context(): void
    {
        [$userA] = $this->seedTenantForUser(role: 'owner');
        [, $companyB] = $this->seedTenantForUser(role: 'owner');

        Sanctum::actingAs($userA);

        $this->getJson('/api/tenant-context-test', ['X-Company-ID' => (string) $companyB->id])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_get_companies_only_returns_companies_assigned_to_authenticated_user(): void
    {
        [$userA, $companyA] = $this->seedTenantForUser(role: 'owner');
        $this->seedTenantForUser(role: 'owner'); // userB + companyB

        Sanctum::actingAs($userA);

        $response = $this->getJson('/api/companies')->assertStatus(200);

        $data = $response->json('data');
        $this->assertIsArray($data);

        $companyIds = array_map(fn ($row) => $row['id'] ?? null, $data);
        $this->assertContains($companyA->id, $companyIds);
        $this->assertCount(1, array_filter($companyIds, fn ($id) => $id === $companyA->id));
        $this->assertCount(1, $companyIds);
    }

    public function test_authenticated_user_cannot_select_another_users_company(): void
    {
        [$userA] = $this->seedTenantForUser(role: 'owner');
        [, $companyB] = $this->seedTenantForUser(role: 'owner');

        Sanctum::actingAs($userA);

        $this->postJson('/api/companies/select', ['company_id' => $companyB->id])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_inactive_tenant_database_is_rejected(): void
    {
        $userA = User::factory()->create(['status' => 'active']);

        $companyA = Company::query()->create([
            'name' => 'Company A',
            'slug' => 'company-a',
            'code' => 'CMP-000101',
            'status' => 'active',
            'created_by' => $userA->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $companyA->id,
            'user_id' => $userA->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $databaseName = 'test_tenant_inactive_101.sqlite';
        $databasePath = database_path('tenants/'.$databaseName);
        $this->ensureTenantFile($databasePath);

        TenantDatabase::query()->create([
            'company_id' => $companyA->id,
            'database_name' => $databaseName,
            'database_path' => $databasePath,
            'driver' => 'sqlite',
            'status' => 'inactive',
        ]);

        Sanctum::actingAs($userA);

        $this->getJson('/api/tenant-context-test', ['X-Company-ID' => (string) $companyA->id])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_forbidden_public_tenant_management_routes_do_not_exist(): void
    {
        $routes = Route::getRoutes();

        $forbidden = [
            ['POST', 'api/companies'],
            ['POST', 'api/tenants'],
            ['POST', 'api/tenant/migrate'],
            ['POST', 'api/company-users'],
            ['POST', 'api/companies/{id}/users'],
        ];

        foreach ($forbidden as [$method, $uri]) {
            $exists = collect($routes)->contains(function ($route) use ($method, $uri) {
                return in_array($method, $route->methods(), true) && $route->uri() === $uri;
            });

            $this->assertFalse($exists, "Forbidden route exists: {$method} {$uri}");
        }
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedTenantForUser(string $role): array
    {
        $user = User::factory()->create(['status' => 'active']);

        $companyIdSeed = (int) $user->id + 100; // stable enough for test data uniqueness
        $slug = 'company-'.$companyIdSeed;
        $code = 'CMP-'.str_pad((string) $companyIdSeed, 6, '0', STR_PAD_LEFT);

        $company = Company::query()->create([
            'name' => 'Company '.$companyIdSeed,
            'slug' => $slug,
            'code' => $code,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $databaseName = 'test_tenant_'.$companyIdSeed.'.sqlite';
        $databasePath = database_path('tenants/'.$databaseName);
        $this->ensureTenantFile($databasePath);

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => $databaseName,
            'database_path' => $databasePath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        return [$user, $company];
    }

    private function ensureTenantFile(string $path): void
    {
        $dir = dirname($path);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        if (! File::exists($path)) {
            File::put($path, '');
        }

        $this->tenantFiles[] = $path;
    }
}
