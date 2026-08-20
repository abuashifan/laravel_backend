<?php

namespace Tests\Feature\Companies;

use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RestoreCompanyTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private array $tenantFiles = [];

    private string $tenantDirectory = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantDirectory = storage_path('framework/testing/tenants-'.uniqid());
        File::ensureDirectoryExists($this->tenantDirectory);
        config(['tenant.database_path' => $this->tenantDirectory]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantFiles as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }

        if ($this->tenantDirectory !== '' && File::isDirectory($this->tenantDirectory)) {
            File::deleteDirectory($this->tenantDirectory);
        }

        parent::tearDown();
    }

    public function test_client_cannot_reach_restore_endpoints(): void
    {
        [$owner, $companyId] = $this->createAndDeleteCompany();

        // Sesi client memakai ability `client`, bukan `platform-admin`.
        Sanctum::actingAs($owner, [TokenAbility::CLIENT]);

        $this->getJson('/api/admin/companies/deleted')->assertStatus(403);
        $this->postJson("/api/admin/companies/{$companyId}/restore")->assertStatus(403);
        $this->deleteJson("/api/admin/companies/{$companyId}/purge", ['confirm_name' => 'PT Maju Jaya'])
            ->assertStatus(403);
    }

    public function test_platform_admin_sees_deleted_company_with_countdown(): void
    {
        [, $companyId] = $this->createAndDeleteCompany();

        $this->actingAsPlatformAdmin();

        $this->getJson('/api/admin/companies/deleted')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $companyId)
            ->assertJsonPath('data.0.name', 'PT Maju Jaya')
            ->assertJsonPath('data.0.can_restore', true)
            ->assertJsonPath('data.0.is_expired', false)
            ->assertJsonPath('data.0.days_remaining', 30)
            ->assertJsonPath('meta.retention_days', 30);
    }

    public function test_platform_admin_can_restore_company_and_owner_can_open_it_again(): void
    {
        [$owner, $companyId] = $this->createAndDeleteCompany();

        $this->actingAsPlatformAdmin();
        $this->postJson("/api/admin/companies/{$companyId}/restore")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('companies', ['id' => $companyId, 'deleted_at' => null]);

        // Kembali utuh dari sisi owner: muncul di picker, kuota terpakai lagi,
        // dan tenant-nya benar-benar bisa dibuka.
        Sanctum::actingAs($owner, ['*']);

        $this->getJson('/api/companies')
            ->assertStatus(200)
            ->assertJsonPath('data.0.id', $companyId)
            ->assertJsonPath('meta.quota.used', 1);

        $this->postJson('/api/companies/select', ['company_id' => $companyId])
            ->assertStatus(200)
            ->assertJsonPath('data.active_company.id', $companyId);

        $this->getJson('/api/setup/status', ['X-Company-ID' => (string) $companyId])
            ->assertStatus(200);
    }

    /**
     * Requirement 3: pemulihan tidak boleh menghidupkan kembali apa pun yang
     * sengaja dinonaktifkan sebelum penghapusan.
     */
    public function test_restore_preserves_member_statuses_exactly(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($owner, ['*']);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(201)
            ->json('data.id');

        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $companyId)->firstOrFail()->database_path
        );

        $inactiveStaff = User::factory()->create(['status' => 'active']);
        CompanyUser::query()->create([
            'company_id' => $companyId,
            'user_id' => $inactiveStaff->id,
            'role' => 'staff',
            'status' => 'inactive',
            'joined_at' => now(),
        ]);

        $this->deleteJson("/api/companies/{$companyId}", ['confirm_name' => 'PT Maju Jaya'])
            ->assertStatus(200);

        $this->actingAsPlatformAdmin();
        $this->postJson("/api/admin/companies/{$companyId}/restore")->assertStatus(200);

        $this->assertDatabaseHas('company_users', [
            'company_id' => $companyId,
            'user_id' => $inactiveStaff->id,
            'status' => 'inactive',
        ]);
    }

    /** Requirement 2: pemulihan tidak boleh membuat kuota client terlampaui. */
    public function test_restore_is_blocked_when_owner_quota_is_full(): void
    {
        // Paket kuota 1: setelah menghapus lalu membuat perusahaan pengganti,
        // slotnya sudah terpakai dan yang lama tidak boleh ikut kembali.
        $plan = Plan::query()->create([
            'name' => 'Basic', 'code' => 'basic', 'max_users' => 5, 'max_companies' => 1, 'status' => 'active',
        ]);

        $owner = User::factory()->create(['status' => 'active', 'plan_id' => $plan->id]);
        Sanctum::actingAs($owner, ['*']);

        $firstId = (int) $this->postJson('/api/companies', ['name' => 'PT Pertama'])
            ->assertStatus(201)
            ->json('data.id');
        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $firstId)->firstOrFail()->database_path
        );

        $this->deleteJson("/api/companies/{$firstId}", ['confirm_name' => 'PT Pertama'])
            ->assertStatus(200);

        $secondId = (int) $this->postJson('/api/companies', ['name' => 'PT Kedua'])
            ->assertStatus(201)
            ->json('data.id');
        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $secondId)->firstOrFail()->database_path
        );

        $this->actingAsPlatformAdmin();

        $this->getJson('/api/admin/companies/deleted')
            ->assertStatus(200)
            ->assertJsonPath('data.0.can_restore', false)
            ->assertJsonPath('data.0.restore_blocker.code', 'COMPANY_RESTORE_QUOTA_EXCEEDED');

        $this->postJson("/api/admin/companies/{$firstId}/restore")
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMPANY_RESTORE_QUOTA_EXCEEDED');

        $this->assertSoftDeleted('companies', ['id' => $firstId]);

        // Setelah slotnya dibebaskan, pemulihan yang sama berhasil.
        Sanctum::actingAs($owner, ['*']);
        $this->deleteJson("/api/companies/{$secondId}", ['confirm_name' => 'PT Kedua'])
            ->assertStatus(200);

        $this->actingAsPlatformAdmin();
        $this->postJson("/api/admin/companies/{$firstId}/restore")->assertStatus(200);

        $this->assertDatabaseHas('companies', ['id' => $firstId, 'deleted_at' => null]);
    }

    /** Requirement 1: lewat 30 hari tidak bisa dipulihkan lagi. */
    public function test_restore_is_blocked_after_retention_window(): void
    {
        [, $companyId] = $this->createAndDeleteCompany();

        Company::onlyTrashed()->whereKey($companyId)->update(['deleted_at' => now()->subDays(31)]);

        $this->actingAsPlatformAdmin();

        $this->getJson('/api/admin/companies/deleted')
            ->assertStatus(200)
            ->assertJsonPath('data.0.can_restore', false)
            ->assertJsonPath('data.0.is_expired', true)
            ->assertJsonPath('data.0.days_remaining', 0);

        $this->postJson("/api/admin/companies/{$companyId}/restore")
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMPANY_RESTORE_WINDOW_EXPIRED');

        $this->assertSoftDeleted('companies', ['id' => $companyId]);
    }

    public function test_sweep_command_purges_only_companies_past_retention(): void
    {
        [, $oldId] = $this->createAndDeleteCompany('PT Lama');
        [, $freshId] = $this->createAndDeleteCompany('PT Baru');

        $oldPath = TenantDatabase::query()->where('company_id', $oldId)->firstOrFail()->database_path;

        Company::onlyTrashed()->whereKey($oldId)->update(['deleted_at' => now()->subDays(31)]);

        // Pemeriksaan dulu: --dry-run tidak boleh mengubah apa pun.
        $this->artisan('companies:sweep-deleted', ['--dry-run' => true])->assertExitCode(0);
        $this->assertSoftDeleted('companies', ['id' => $oldId]);
        $this->assertTrue(File::exists($oldPath));

        $this->artisan('companies:sweep-deleted')->assertExitCode(0);

        // Yang lewat tenggat hilang total — baris, tabel turunan, dan filenya.
        $this->assertDatabaseMissing('companies', ['id' => $oldId]);
        $this->assertDatabaseMissing('company_users', ['company_id' => $oldId]);
        $this->assertDatabaseMissing('tenant_databases', ['company_id' => $oldId]);
        $this->assertFalse(File::exists($oldPath));

        // Yang masih dalam masa pemulihan tidak tersentuh.
        $this->assertSoftDeleted('companies', ['id' => $freshId]);
    }

    public function test_platform_admin_can_purge_early_to_free_a_slot(): void
    {
        [, $companyId] = $this->createAndDeleteCompany();
        $path = TenantDatabase::query()->where('company_id', $companyId)->firstOrFail()->database_path;

        $this->actingAsPlatformAdmin();

        $this->deleteJson("/api/admin/companies/{$companyId}/purge", ['confirm_name' => 'Nama Salah'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['confirm_name']]);
        $this->assertSoftDeleted('companies', ['id' => $companyId]);

        $this->deleteJson("/api/admin/companies/{$companyId}/purge", ['confirm_name' => 'PT Maju Jaya'])
            ->assertStatus(200);

        $this->assertDatabaseMissing('companies', ['id' => $companyId]);
        $this->assertFalse(File::exists($path));
    }

    /**
     * @return array{0: User, 1: int}
     */
    private function createAndDeleteCompany(string $name = 'PT Maju Jaya'): array
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => 'pro'],
            ['name' => 'Pro', 'max_users' => 10, 'max_companies' => 5, 'status' => 'active'],
        );

        $owner = User::factory()->create(['status' => 'active', 'plan_id' => $plan->id]);
        Sanctum::actingAs($owner, ['*']);

        $companyId = (int) $this->postJson('/api/companies', ['name' => $name])
            ->assertStatus(201)
            ->json('data.id');

        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $companyId)->firstOrFail()->database_path
        );

        $this->deleteJson("/api/companies/{$companyId}", ['confirm_name' => $name])
            ->assertStatus(200);

        return [$owner, $companyId];
    }

    private function actingAsPlatformAdmin(): User
    {
        $admin = User::factory()->create(['status' => 'active', 'is_platform_admin' => true]);
        Sanctum::actingAs($admin, [TokenAbility::PLATFORM_ADMIN]);

        return $admin;
    }

    private function trackTenantFile(?string $path): void
    {
        if (is_string($path) && $path !== '') {
            $this->tenantFiles[] = $path;
        }
    }
}
