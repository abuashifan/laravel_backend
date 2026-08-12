<?php

namespace Tests\Feature\Imports;

use App\Modules\Imports\Models\ImportBatch;
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

class ExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_errors_returns_xlsx_for_batch_with_errors(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->postJson('/api/imports', [
            'profile' => 'contact',
            'file' => $this->csvFile('contacts.csv', [
                ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
                ['', '', '', '', '', '', ''],
                ['CUST-001', 'PT Satu', 'customer', '', '', '', ''],
            ]),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => ['code' => 'Code', 'name' => 'Name', 'type' => 'Type', 'email' => 'Email', 'phone' => 'Phone', 'address' => 'Address', 'tax_number' => 'Tax Number'],
        ], $ctx['headers'])->assertOk();

        $res = $this->getJson('/api/imports/'.$batch['uuid'].'/export-errors', $ctx['headers']);
        $res->assertOk();
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_sales_invoice_export_returns_xlsx(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->getJson('/api/sales/invoices/export', $ctx['headers']);
        $res->assertOk();
        $res->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'Export Test '.$user->id, 'slug' => 'export-test-'.$user->id,
            'code' => 'EXP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $tenantPath = database_path('tenants/test_exp_'.$company->id.'_'.uniqid().'.sqlite');
        File::ensureDirectoryExists(dirname($tenantPath));
        File::put($tenantPath, '');
        $this->registerTenantFile($tenantPath);
        TenantDatabase::query()->create([
            'company_id' => $company->id, 'database_name' => basename($tenantPath),
            'database_path' => $tenantPath, 'driver' => 'sqlite', 'status' => 'active',
        ]);
        app(TenantConnectionManager::class)->connect($tenantPath);
        Artisan::call('migrate', ['--database' => 'tenant', '--path' => 'database/migrations/tenant', '--force' => true]);
        Sanctum::actingAs($user, ['*']);

        return ['user' => $user, 'company' => $company, 'headers' => ['X-Company-ID' => (string) $company->id]];
    }

    private function csvFile(string $name, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'exp_csv_');
        $h = fopen($path, 'w');
        foreach ($rows as $r) { fputcsv($h, $r); }
        fclose($h);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
