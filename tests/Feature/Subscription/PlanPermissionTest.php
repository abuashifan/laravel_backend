<?php

namespace Tests\Feature\Subscription;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\CompanyUserPermissionOverride;
use App\Shared\Models\Permission;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Permission\EffectivePermissionService;
use App\Shared\Permission\PermissionCatalogService;
use App\Shared\Subscription\PlanPermissionResolver;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Lapis 1 — paket, disisipkan ke jalur permission yang sudah ada.
 *
 * Yang dikunci di sini adalah dua sumbu yang sengaja tidak dilebur: paket
 * mengikat semua orang termasuk owner, permission hanya mengatur user tambahan.
 */
class PlanPermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tenantFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Peta uji, bukan peta produksi: test ini menguji mesinnya, bukan isi
        // tier yang baru ditetapkan Fase 2.
        config([
            'plan_features.enforce' => true,
            'plan_features.features' => [
                'multi_warehouse' => [
                    'warehouses.create',
                    'warehouses.edit',
                    'warehouses.deactivate',
                ],
                'audit_trail' => ['audit.*'],
                'budgeting' => ['budgets.manage'],
            ],
        ]);

        app(PlanPermissionResolver::class)->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        parent::tearDown();
    }

    public function test_permission_passes_when_the_plan_includes_the_feature(): void
    {
        // Role `finance` memang memuat `budgets.manage`, jadi yang diuji di
        // sini murni lapis 1 — lapis 2 sudah pasti lolos.
        [$user, $company] = $this->seedCompany(['budgeting'], role: 'finance');

        $this->assertTrue($this->can($company, $user, 'budgets.manage'));
        $this->assertSame('role_default', $this->explain($company, $user, 'budgets.manage')['source']);
    }

    public function test_permission_is_refused_when_the_plan_omits_the_feature(): void
    {
        [$user, $company] = $this->seedCompany([], role: 'finance');

        $this->assertFalse($this->can($company, $user, 'budgets.manage'));
        $this->assertSame('not_in_plan', $this->explain($company, $user, 'budgets.manage')['source']);
    }

    /**
     * Test terpenting di fase ini. `hasPermission()` punya jalan pintas `*`
     * yang melewati corong permission sepenuhnya; kalau lapis paket dipasang di
     * bawahnya, owner tembus ke semua fitur — dan owner ada di SETIAP
     * perusahaan, jadi gerbangnya akan tampak bekerja saat diuji dengan role
     * staff lalu bocor total pada orang yang paling sering memakainya.
     */
    public function test_owner_with_wildcard_is_still_bound_by_the_plan(): void
    {
        [$owner, $company] = $this->seedCompany([], role: 'owner');

        $this->assertContains('*', app(EffectivePermissionService::class)
            ->rolePermissionsForCompanyUser($this->companyUser($company, $owner)));

        $this->assertFalse($this->can($company, $owner, 'warehouses.create'));
        $this->assertSame('not_in_plan', $this->explain($company, $owner, 'warehouses.create')['source']);
    }

    public function test_owner_still_passes_layer_two_for_permissions_the_plan_opens(): void
    {
        [$owner, $company] = $this->seedCompany(['multi_warehouse'], role: 'owner');

        $this->assertTrue($this->can($company, $owner, 'warehouses.create'));
        $this->assertSame('role_default', $this->explain($company, $owner, 'warehouses.create')['source']);
    }

    public function test_deny_override_still_beats_the_wildcard(): void
    {
        [$owner, $company] = $this->seedCompany(['multi_warehouse'], role: 'owner');
        $companyUser = $this->companyUser($company, $owner);

        $permission = Permission::query()->create(
            app(PermissionCatalogService::class)->fromKey('warehouses.create', 1)
        );

        CompanyUserPermissionOverride::query()->create([
            'company_user_id' => $companyUser->id,
            'permission_id' => $permission->id,
            'effect' => 'deny',
        ]);

        // Paket membuka, tapi lapis 2 menutup — dan alasannya harus tetap
        // "izin", bukan "paket".
        $this->assertFalse($this->can($company, $owner, 'warehouses.create'));
        $this->assertSame('user_override_deny', $this->explain($company, $owner, 'warehouses.create')['source']);
    }

    public function test_prefixes_outside_the_map_are_always_open(): void
    {
        [$owner, $company] = $this->seedCompany([], role: 'owner');

        // `sales.*` tidak disebut di peta mana pun — daftar putih, bukan daftar
        // hitam, supaya modul baru tidak diam-diam mati di semua tier.
        $this->assertTrue($this->can($company, $owner, 'sales.create'));
        $this->assertSame('role_default', $this->explain($company, $owner, 'sales.create')['source']);
    }

    public function test_read_permissions_survive_a_tier_downgrade(): void
    {
        [$owner, $company] = $this->seedCompany([], role: 'owner');

        // Client yang turun tier tetap boleh MEMBACA data yang terlanjur
        // dibuatnya; hanya izin tulis yang digerbangi.
        $this->assertTrue($this->can($company, $owner, 'warehouses.view'));
        $this->assertFalse($this->can($company, $owner, 'warehouses.create'));
    }

    public function test_wildcard_pattern_closes_the_whole_prefix(): void
    {
        [$owner, $company] = $this->seedCompany([], role: 'owner');

        $this->assertFalse($this->can($company, $owner, 'audit.view'));

        [$owner2, $company2] = $this->seedCompany(['audit_trail'], role: 'owner');
        $this->assertTrue($this->can($company2, $owner2, 'audit.view'));
    }

    public function test_client_without_a_plan_falls_back_to_free(): void
    {
        Plan::query()->create([
            'name' => 'Free',
            'code' => 'free',
            'max_users' => 1,
            'max_companies' => 1,
            'status' => 'active',
            'features' => ['basic_accounting'],
        ]);

        [$owner, $company] = $this->seedCompany(null, role: 'owner');

        $this->assertSame([], array_diff(['basic_accounting'], $this->resolver()->featuresFor($company)));
        $this->assertFalse($this->can($company, $owner, 'warehouses.create'));
    }

    public function test_effective_permission_list_is_filtered_before_reaching_the_frontend(): void
    {
        // Katalog izin lengkap perlu ada di database: `allPermissionKeys()`
        // membacanya dari sana, dan migrasi saja hanya mengisi sebagiannya.
        $this->seed(PermissionSeeder::class);

        [$owner, $company] = $this->seedCompany([], role: 'owner');
        Sanctum::actingAs($owner, ['*']);

        $permissions = $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200)
            ->json('data.permissions');

        $this->assertNotContains('warehouses.create', $permissions);
        $this->assertContains('warehouses.view', $permissions);
    }

    public function test_permissions_endpoint_reports_the_plan_features(): void
    {
        [$owner, $company] = $this->seedCompany(['multi_warehouse', 'audit_trail'], role: 'owner');
        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/auth/permissions', ['X-Company-ID' => (string) $company->id])
            ->assertStatus(200)
            ->assertJsonPath('data.plan_features', ['multi_warehouse', 'audit_trail']);
    }

    public function test_route_refused_by_the_plan_answers_feature_not_in_plan(): void
    {
        [$owner, $company] = $this->seedCompany([], role: 'owner');
        Sanctum::actingAs($owner, ['*']);

        $this->postJson('/api/master-data/warehouses', ['name' => 'Gudang Utama', 'code' => 'GDG-01'], [
            'X-Company-ID' => (string) $company->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'FEATURE_NOT_IN_PLAN');
    }

    public function test_route_refused_by_permission_still_answers_permission_denied(): void
    {
        // Paket membuka gudang; yang kurang adalah izin si viewer. Menyuruhnya
        // menaikkan paket akan mengirim dia ke orang yang salah.
        [$viewer, $company] = $this->seedCompany(['multi_warehouse'], role: 'viewer');
        Sanctum::actingAs($viewer, ['*']);

        $this->postJson('/api/master-data/warehouses', ['name' => 'Gudang Utama', 'code' => 'GDG-01'], [
            'X-Company-ID' => (string) $company->id,
        ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'PERMISSION_DENIED');
    }

    public function test_switch_off_restores_the_behaviour_from_before_this_phase(): void
    {
        config(['plan_features.enforce' => false]);
        $this->resolver()->flush();

        [$owner, $company] = $this->seedCompany([], role: 'owner');

        $this->assertSame([], $this->resolver()->blockedKeysFor($company));
        $this->assertTrue($this->can($company, $owner, 'warehouses.create'));
        $this->assertSame('role_default', $this->explain($company, $owner, 'warehouses.create')['source']);
    }

    private function resolver(): PlanPermissionResolver
    {
        return app(PlanPermissionResolver::class);
    }

    private function can(Company $company, User $user, string $key): bool
    {
        return app(EffectivePermissionService::class)
            ->hasPermission($this->companyUser($company, $user), $key);
    }

    /**
     * @return array{permission:string, allowed:bool, source:string}
     */
    private function explain(Company $company, User $user, string $key): array
    {
        return app(EffectivePermissionService::class)
            ->explainPermission($this->companyUser($company, $user), $key);
    }

    private function companyUser(Company $company, User $user): CompanyUser
    {
        return CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->firstOrFail();
    }

    /**
     * @param  list<string>|null  $features  null = client tanpa paket sama sekali
     * @return array{0: User, 1: Company}
     */
    private function seedCompany(?array $features, string $role): array
    {
        $planId = null;

        if ($features !== null) {
            $planId = Plan::query()->create([
                'name' => 'Uji '.uniqid(),
                'code' => 'uji-'.uniqid(),
                'max_users' => 10,
                'max_companies' => 10,
                'status' => 'active',
                'features' => $features,
            ])->id;
        }

        $user = User::factory()->create(['status' => 'active', 'plan_id' => $planId]);

        $company = Company::query()->create([
            'name' => 'PT Uji '.$user->id,
            'slug' => 'pt-uji-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
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

        $tenantPath = database_path('tenants/test_planpermission_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');
        $this->tenantFiles[] = $tenantPath;

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($tenantPath),
            'database_path' => $tenantPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        return [$user, $company];
    }
}
