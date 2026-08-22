<?php

namespace Tests\Feature\Imports;

use App\Modules\Imports\Models\ImportBatch;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `php artisan imports:sweep-retention` — masa simpan berkas impor per tier
 * (Fase 4, skema tier). Cakupan saat ini: batch berstatus `failed`, satu-
 * satunya status terminal yang sungguhan ada hari ini di modul Imports.
 */
class SweepImportRetentionCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
    }

    public function test_deletes_failed_batches_older_than_the_tier_retention(): void
    {
        Storage::fake('local');
        $company = $this->tenantFor('basic'); // retensi 30 hari

        $old = $this->createBatch($company, 'failed', now()->subDays(35));
        $recent = $this->createBatch($company, 'failed', now()->subDays(5));

        Storage::disk('local')->assertExists($old->stored_path);

        $this->artisan('imports:sweep-retention')->assertSuccessful();

        $this->assertDatabaseMissing('import_batches', ['id' => $old->id], 'tenant');
        $this->assertDatabaseHas('import_batches', ['id' => $recent->id], 'tenant');
        Storage::disk('local')->assertMissing($old->stored_path);
    }

    public function test_active_batches_are_never_deleted_regardless_of_age(): void
    {
        Storage::fake('local');
        $company = $this->tenantFor('basic');

        $active = $this->createBatch($company, 'validating', now()->subDays(365));

        $this->artisan('imports:sweep-retention')->assertSuccessful();

        $this->assertDatabaseHas('import_batches', ['id' => $active->id], 'tenant');
    }

    public function test_enterprise_tier_keeps_batches_longer_than_basic(): void
    {
        Storage::fake('local');
        $company = $this->tenantFor('enterprise'); // retensi 90 hari

        $batch = $this->createBatch($company, 'failed', now()->subDays(40));

        $this->artisan('imports:sweep-retention')->assertSuccessful();

        // 40 hari < 90 hari retensi Enterprise — belum dihapus.
        $this->assertDatabaseHas('import_batches', ['id' => $batch->id], 'tenant');
    }

    public function test_dry_run_deletes_nothing(): void
    {
        Storage::fake('local');
        $company = $this->tenantFor('basic');

        $old = $this->createBatch($company, 'failed', now()->subDays(35));

        $this->artisan('imports:sweep-retention --dry-run')->assertSuccessful();

        $this->assertDatabaseHas('import_batches', ['id' => $old->id], 'tenant');
        Storage::disk('local')->assertExists($old->stored_path);
    }

    private function createBatch(Company $company, string $status, Carbon $createdAt): ImportBatch
    {
        app(TenantConnectionManager::class)->connect($company->tenantDatabase->database_path);

        $storedPath = 'imports/'.$company->id.'/'.uniqid().'.csv';
        Storage::disk('local')->put($storedPath, 'ref,customer,amount');

        $batch = ImportBatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'profile' => 'sales_invoice',
            'original_filename' => 'sales.csv',
            'stored_path' => $storedPath,
            'file_hash' => hash('sha256', $storedPath),
            'status' => $status,
            'created_by' => $company->created_by,
        ]);

        // create() selalu menimpa created_at dengan now() lewat Eloquent —
        // query builder mentah tidak melakukan itu, jadi dipakai di sini
        // supaya tanggal lampau benar-benar tersimpan.
        DB::connection('tenant')->table('import_batches')
            ->where('id', $batch->id)
            ->update(['created_at' => $createdAt, 'updated_at' => $createdAt]);

        return $batch->refresh();
    }

    private function tenantFor(string $planCode): Company
    {
        $user = $this->clientOn($planCode);

        $company = Company::query()->create([
            'name' => 'Company Retention '.$user->id,
            'slug' => 'company-retention-'.$user->id,
            'code' => 'CMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tenantPath = database_path('tenants/test_retention_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');
        $this->registerTenantFile($tenantPath);

        TenantDatabase::query()->create([
            'company_id' => $company->id,
            'database_name' => basename($tenantPath),
            'database_path' => $tenantPath,
            'driver' => 'sqlite',
            'status' => 'active',
        ]);

        app(TenantConnectionManager::class)->connect($tenantPath);

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations/tenant',
            '--force' => true,
        ]);

        return $company->fresh('tenantDatabase');
    }
}
