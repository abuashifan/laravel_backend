<?php

namespace Tests\Feature\Imports;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kuota penyimpanan digerbangi SEBELUM unggahan impor diterima (Fase 4,
 * skema tier §"Cara menegakkannya").
 */
class StorageQuotaImportGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedTierPlans();
    }

    public function test_upload_is_refused_when_it_would_exceed_the_quota(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant('basic');

        // Basic: 1024 MB. Sudah terpakai 1023 MB dari pengukuran terakhir.
        $ctx['company']->tenantDatabase()->update([
            'size_bytes' => 1023 * 1024 * 1024,
            'measured_at' => now(),
        ]);

        $res = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', [
                ['Ref', 'Customer', 'Amount'],
                ['INV-001', 'PT A', '1000'],
            ], 2 * 1024 * 1024),
        ], $ctx['headers']);

        $res->assertStatus(422);
        $res->assertJsonPath('code', 'STORAGE_QUOTA_EXCEEDED');
        $this->assertArrayHasKey('percent_used', $res->json('meta'));
    }

    public function test_upload_succeeds_when_well_within_the_quota(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant('basic');

        $res = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', [
                ['Ref', 'Customer', 'Amount'],
                ['INV-001', 'PT A', '1000'],
            ]),
        ], $ctx['headers']);

        $res->assertStatus(201);
    }

    public function test_pro_tier_has_more_headroom_than_basic_for_the_same_usage(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant('pro');

        // Basic tidak akan lolos di angka ini (kuota 1024 MB); Pro (2048 MB) lolos.
        $ctx['company']->tenantDatabase()->update([
            'size_bytes' => 1023 * 1024 * 1024,
            'measured_at' => now(),
        ]);

        $res = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', [
                ['Ref', 'Customer', 'Amount'],
                ['INV-001', 'PT A', '1000'],
            ], 2 * 1024 * 1024),
        ], $ctx['headers']);

        $res->assertStatus(201);
    }

    /**
     * @return array{user: User, company: Company, headers: array<string,string>}
     */
    private function setUpTenant(string $planCode): array
    {
        $user = $this->clientOn($planCode);

        $company = Company::query()->create([
            'name' => 'Company Import '.$user->id,
            'slug' => 'company-import-'.$user->id,
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

        $tenantPath = database_path('tenants/test_storagequota_'.$company->id.'_'.uniqid().'.sqlite');
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

        Sanctum::actingAs($user, ['*']);

        return [
            'user' => $user,
            'company' => $company,
            'headers' => ['X-Company-ID' => (string) $company->id],
        ];
    }

    private function csvFile(string $name, array $rows, ?int $padToBytes = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_csv_');
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        if ($padToBytes !== null) {
            $current = ftell($handle);
            if ($padToBytes > $current) {
                fwrite($handle, str_repeat('#', $padToBytes - $current));
            }
        }

        fclose($handle);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
