<?php

namespace Tests\Feature\Companies;

use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DeleteCompanyTest extends TestCase
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

    public function test_unauthenticated_user_cannot_delete_company(): void
    {
        $this->deleteJson('/api/companies/1', ['confirm_name' => 'PT Apa Saja'])
            ->assertStatus(401);
    }

    public function test_owner_can_delete_company_with_matching_confirmation(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user, ['*']);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(201)
            ->json('data.id');

        $tenantDatabase = TenantDatabase::query()->where('company_id', $companyId)->firstOrFail();
        $this->trackTenantFile($tenantDatabase->database_path);

        $this->deleteJson("/api/companies/{$companyId}", ['confirm_name' => 'PT Maju Jaya'])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('companies', ['id' => $companyId]);

        // Yang berubah HANYA `deleted_at`. Status staf dan tenant database
        // dibiarkan apa adanya supaya pemulihan mengembalikan keadaan persis
        // seperti sebelum dihapus.
        $this->assertDatabaseHas('company_users', [
            'company_id' => $companyId,
            'user_id' => $user->id,
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('tenant_databases', [
            'company_id' => $companyId,
            'status' => 'active',
        ]);

        // File tenant sengaja tidak dihapus fisik — hanya ditandai nonaktif.
        $this->assertTrue(File::exists($tenantDatabase->database_path));

        // Hilang dari picker dan kuota bebas lagi.
        $this->getJson('/api/companies')
            ->assertStatus(200)
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.quota.used', 0);

        // Tidak bisa lagi dipilih setelah dihapus.
        $this->postJson('/api/companies/select', ['company_id' => $companyId])
            ->assertStatus(422);
    }

    /**
     * Inti requirement pemulihan: status yang sengaja dibuat tidak-aktif
     * sebelum penghapusan harus tetap tidak-aktif setelahnya, bukan ikut
     * disapu jadi `removed` seperti perilaku versi awal.
     */
    public function test_delete_preserves_existing_member_statuses(): void
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

        $this->assertDatabaseHas('company_users', [
            'company_id' => $companyId,
            'user_id' => $inactiveStaff->id,
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('company_users', [
            'company_id' => $companyId,
            'user_id' => $owner->id,
            'status' => 'active',
        ]);
    }

    public function test_delete_requires_matching_confirm_name(): void
    {
        $user = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($user, ['*']);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(201)
            ->json('data.id');

        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $companyId)->firstOrFail()->database_path
        );

        $this->deleteJson("/api/companies/{$companyId}", ['confirm_name' => 'Nama Salah'])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['confirm_name']]);

        $this->assertDatabaseHas('companies', ['id' => $companyId, 'deleted_at' => null]);
    }

    public function test_non_owner_member_cannot_delete_company(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($owner, ['*']);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(201)
            ->json('data.id');

        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $companyId)->firstOrFail()->database_path
        );

        $staff = User::factory()->create(['status' => 'active']);
        CompanyUser::query()->create([
            'company_id' => $companyId,
            'user_id' => $staff->id,
            'role' => 'staff',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        Sanctum::actingAs($staff, ['*']);

        $this->deleteJson("/api/companies/{$companyId}", ['confirm_name' => 'PT Maju Jaya'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_DELETE_NOT_OWNER');

        $this->assertDatabaseHas('companies', ['id' => $companyId, 'deleted_at' => null]);
    }

    public function test_user_without_access_cannot_delete_company(): void
    {
        $owner = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($owner, ['*']);

        $companyId = (int) $this->postJson('/api/companies', ['name' => 'PT Maju Jaya'])
            ->assertStatus(201)
            ->json('data.id');

        $this->trackTenantFile(
            TenantDatabase::query()->where('company_id', $companyId)->firstOrFail()->database_path
        );

        $outsider = User::factory()->create(['status' => 'active']);
        Sanctum::actingAs($outsider, ['*']);

        $this->deleteJson("/api/companies/{$companyId}", ['confirm_name' => 'PT Maju Jaya'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'COMPANY_DELETE_NOT_OWNER');
    }

    private function trackTenantFile(?string $path): void
    {
        if (is_string($path) && $path !== '') {
            $this->tenantFiles[] = $path;
        }
    }
}
