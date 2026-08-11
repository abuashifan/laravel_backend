<?php

namespace Tests\Feature\Admin;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminClientManagementTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
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

    // ── Pemisahan pintu masuk ────────────────────────────────────────────────

    public function test_platform_admin_cannot_login_through_client_endpoint(): void
    {
        $admin = $this->platformAdmin();

        $this->postJson('/api/auth/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_client_cannot_login_through_admin_endpoint(): void
    {
        $client = User::factory()->create([
            'status' => 'active',
            'password' => Hash::make('password123'),
        ]);

        $this->postJson('/api/admin/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_admin_login_returns_token_and_flag(): void
    {
        $admin = $this->platformAdmin();

        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertStatus(200)
            ->assertJsonPath('data.user.is_platform_admin', true)
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'name', 'email']]]);
    }

    public function test_inactive_admin_cannot_login(): void
    {
        $admin = $this->platformAdmin();
        $admin->forceFill(['status' => 'inactive'])->save();

        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'password123'])
            ->assertStatus(403);
    }

    // ── Batas kemampuan token ────────────────────────────────────────────────

    public function test_admin_token_cannot_open_company_data(): void
    {
        $admin = $this->platformAdmin();
        $token = $this->adminToken($admin);

        // Perusahaan milik client, lengkap dengan tenant aktif.
        [$client, $company] = $this->seedClientWithCompany();

        // Admin bahkan tidak pernah terdaftar di perusahaan itu, tapi yang lebih
        // penting: tokennya tidak membawa ability client, jadi tertahan lebih
        // dulu di company.access.
        $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'X-Company-ID' => (string) $company->id,
        ])->getJson('/api/setup/status')->assertStatus(403);

        $this->assertNotNull($client->id);
    }

    public function test_client_token_cannot_reach_admin_endpoints(): void
    {
        $this->platformAdmin();

        $client = User::factory()->create(['status' => 'active', 'password' => Hash::make('password123')]);
        $clientToken = $this->postJson('/api/auth/login', [
            'email' => $client->email,
            'password' => 'password123',
        ])->assertStatus(200)->json('data.token');

        $this->withHeader('Authorization', 'Bearer '.$clientToken)
            ->getJson('/api/admin/clients')
            ->assertStatus(403);
    }

    public function test_admin_endpoints_reject_anonymous(): void
    {
        $this->getJson('/api/admin/clients')->assertStatus(401);
    }

    // ── Pengelolaan client ───────────────────────────────────────────────────

    public function test_admin_can_list_clients_with_quota_usage(): void
    {
        $admin = $this->platformAdmin();
        [$client] = $this->seedClientWithCompany();

        $response = $this->actingAsAdmin($admin)
            ->getJson('/api/admin/clients?page=1&per_page=25')
            ->assertStatus(200);

        $rows = $response->json('data.data');
        $this->assertCount(1, $rows, 'Akun admin aplikasi tidak boleh ikut terdaftar sebagai client.');
        $this->assertSame($client->id, $rows[0]['id']);
        $this->assertSame(1, $rows[0]['companies_used']);
        $this->assertSame(1, $rows[0]['companies_limit']);
        $this->assertFalse($rows[0]['over_quota']);
    }

    public function test_admin_can_create_client_with_plan(): void
    {
        $admin = $this->platformAdmin();
        $plan = Plan::query()->create([
            'name' => 'Pro', 'code' => 'pro', 'max_users' => 10, 'max_companies' => 3, 'status' => 'active',
        ]);

        $this->actingAsAdmin($admin)
            ->postJson('/api/admin/clients', [
                'name' => 'Client Baru',
                'email' => 'baru@client.com',
                'password' => 'password123',
                'plan_id' => $plan->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.email', 'baru@client.com')
            ->assertJsonPath('data.plan.code', 'pro')
            ->assertJsonPath('data.companies_limit', 3);

        $created = User::query()->where('email', 'baru@client.com')->firstOrFail();
        $this->assertSame('active', $created->status);
        $this->assertFalse($created->is_platform_admin);
        $this->assertTrue(Hash::check('password123', $created->password));
    }

    public function test_admin_can_deactivate_client_and_login_is_blocked(): void
    {
        $admin = $this->platformAdmin();
        $client = User::factory()->create(['status' => 'active', 'password' => Hash::make('password123')]);

        $this->actingAsAdmin($admin)
            ->patchJson('/api/admin/clients/'.$client->id, ['status' => 'inactive'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'inactive');

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(403);
    }

    public function test_admin_can_change_plan_and_quota_follows(): void
    {
        $admin = $this->platformAdmin();
        $pro = Plan::query()->create([
            'name' => 'Pro', 'code' => 'pro', 'max_users' => 10, 'max_companies' => 3, 'status' => 'active',
        ]);
        $client = User::factory()->create(['status' => 'active']);

        $this->actingAsAdmin($admin)
            ->patchJson('/api/admin/clients/'.$client->id.'/plan', ['plan_id' => $pro->id])
            ->assertStatus(200)
            ->assertJsonPath('data.companies_limit', 3);

        $this->actingAsAdmin($admin)
            ->patchJson('/api/admin/clients/'.$client->id.'/plan', ['plan_id' => null])
            ->assertStatus(200)
            ->assertJsonPath('data.plan', null);
    }

    public function test_admin_can_reset_client_password_and_old_sessions_die(): void
    {
        $admin = $this->platformAdmin();
        $client = User::factory()->create(['status' => 'active', 'password' => Hash::make('password123')]);

        $this->postJson('/api/auth/login', [
            'email' => $client->email,
            'password' => 'password123',
        ])->assertStatus(200);

        $this->assertSame(1, $client->tokens()->count());

        $this->actingAsAdmin($admin)
            ->postJson('/api/admin/clients/'.$client->id.'/reset-password', ['password' => 'passwordbaru1'])
            ->assertStatus(200);

        // Sesi lama ikut mati supaya password lama benar-benar tidak berguna.
        // Diperiksa di level state, bukan lewat request: guard Sanctum menyimpan
        // user yang sudah diselesaikan di request sebelumnya dalam satu test.
        $this->assertSame(0, $client->tokens()->count());

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'password123'])
            ->assertStatus(422);

        $this->postJson('/api/auth/login', ['email' => $client->email, 'password' => 'passwordbaru1'])
            ->assertStatus(200);
    }

    public function test_admin_cannot_touch_another_platform_admin(): void
    {
        $admin = $this->platformAdmin();
        $otherAdmin = User::factory()->create([
            'status' => 'active',
            'is_platform_admin' => true,
            'password' => Hash::make('password123'),
        ]);

        $this->actingAsAdmin($admin)
            ->patchJson('/api/admin/clients/'.$otherAdmin->id, ['status' => 'inactive'])
            ->assertStatus(404);

        // Termasuk dirinya sendiri: tanpa ini admin bisa mengunci diri di luar.
        $this->actingAsAdmin($admin)
            ->patchJson('/api/admin/clients/'.$admin->id, ['status' => 'inactive'])
            ->assertStatus(404);

        $this->assertSame('active', $admin->refresh()->status);
    }

    public function test_admin_area_never_exposes_company_data_routes(): void
    {
        $adminRoutes = collect(Route::getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'api/admin'));

        $this->assertTrue($adminRoutes->isNotEmpty());

        foreach ($adminRoutes as $route) {
            $this->assertNotContains(
                'company.access',
                $route->gatherMiddleware(),
                'Route admin tidak boleh memakai company.access: '.$route->uri()
            );
        }
    }

    // ── Helper ───────────────────────────────────────────────────────────────

    private function platformAdmin(): User
    {
        return User::factory()->create([
            'status' => 'active',
            'is_platform_admin' => true,
            'password' => Hash::make('password123'),
        ]);
    }

    private function adminToken(User $admin): string
    {
        return $this->postJson('/api/admin/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->assertStatus(200)->json('data.token');
    }

    private function actingAsAdmin(User $admin): self
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->adminToken($admin));
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function seedClientWithCompany(): array
    {
        $client = User::factory()->create(['status' => 'active']);

        $company = Company::query()->create([
            'name' => 'PT Client',
            'slug' => 'pt-client',
            'code' => 'CMP-008001',
            'status' => 'active',
            'created_by' => $client->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $client->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $databaseName = 'test_admin_tenant_'.$company->id.'.sqlite';
        $databasePath = database_path('tenants/'.$databaseName);
        File::ensureDirectoryExists(dirname($databasePath));
        if (! File::exists($databasePath)) {
            File::put($databasePath, '');
        }
        $this->tenantFiles[] = $databasePath;

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => $databaseName,
            'database_path' => $databasePath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        return [$client, $company];
    }
}
