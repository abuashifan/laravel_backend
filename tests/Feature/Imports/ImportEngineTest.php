<?php

namespace Tests\Feature\Imports;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_and_xlsx_upload_return_the_same_headers(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $rows = [
            ['Ref', 'Customer', 'Amount'],
            ['INV-20260811-001', 'PT A', '1000'],
        ];

        $csv = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', $rows),
        ], $ctx['headers'])->assertCreated()->json('data');

        $xlsx = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->xlsxFile('sales.xlsx', $rows),
        ], $ctx['headers'])->assertCreated()->json('data');

        $this->assertSame($csv['headers'], $xlsx['headers']);
        $this->assertSame('imports/'.$ctx['company']->id.'/'.$csv['batch']['uuid'].'.csv', $csv['batch']['stored_path']);
    }

    public function test_header_only_file_is_rejected_with_readable_error(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('header-only.csv', [['Ref', 'Customer']]),
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'IMPORT_FILE_INVALID')
            ->assertJsonPath('errors.file.0', 'Berkas impor harus memiliki minimal satu baris data.');
    }

    public function test_mapping_is_saved_and_validation_marks_rows_individually(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        // Siapkan kontak dan produk agar lookup committer berhasil.
        Contact::query()->create([
            'contact_code' => 'PT-A', 'name' => 'PT A', 'contact_type' => 'customer',
            'is_customer' => true, 'is_active' => true,
        ]);
        Product::query()->create([
            'product_code' => 'PRD-001', 'product_name' => 'Produk A', 'product_type' => 'goods',
            'is_active' => true,
        ]);

        $batch = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', [
                ['Ref', 'Customer', 'Invoice Date', 'Item', 'Quantity', 'Unit Price'],
                ['INV-20260811-001', 'PT A', '11/08/2026', 'Produk A', '2', '50000'],
                ['', 'PT B', '11/08/2026', 'Produk A', '1', '75000'],
            ]),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => [
                'ref' => 'Ref',
                'customer' => 'Customer',
                'invoice_date' => 'Invoice Date',
                'item' => 'Item',
                'quantity' => 'Quantity',
                'unit_price' => 'Unit Price',
            ],
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'previewed')
            ->assertJsonPath('data.total_rows', 2)
            ->assertJsonPath('data.valid_rows', 1)
            ->assertJsonPath('data.failed_rows', 1)
            ->assertJsonPath('data.column_map.ref', 'Ref');

        $this->getJson('/api/imports/'.$batch['uuid'].'/rows?page=1&per_page=10', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'valid')
            ->assertJsonPath('data.data.1.status', 'invalid')
            ->assertJsonPath('data.data.1.errors.ref.0', 'Ref wajib diisi.');
    }

    public function test_duplicate_external_ref_from_previous_batch_is_invalid_in_preview(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $first = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('first.csv', [
                ['Ref', 'Customer'],
                ['INV-20260811-001', 'PT A'],
            ]),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$first['uuid'].'/mapping', [
            'column_map' => ['ref' => 'Ref', 'customer' => 'Customer'],
        ], $ctx['headers'])->assertOk();
        ImportBatch::query()->where('uuid', $first['uuid'])->update(['status' => 'completed']);

        $second = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('second.csv', [
                ['Ref', 'Customer'],
                ['INV-20260811-001', 'PT B'],
            ]),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$second['uuid'].'/mapping', [
            'column_map' => ['ref' => 'Ref', 'customer' => 'Customer'],
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.valid_rows', 0)
            ->assertJsonPath('data.failed_rows', 1);

        $this->assertStringContainsString(
            $first['uuid'],
            (string) ImportRow::query()->where('import_batch_id', $second['id'])->firstOrFail()->errors['ref'][0]
        );
    }

    public function test_duplicate_file_hash_returns_warning_until_confirmed(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $rows = [
            ['Ref', 'Customer'],
            ['INV-20260811-001', 'PT A'],
        ];

        $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', $rows),
        ], $ctx['headers'])->assertCreated();

        $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales-again.csv', $rows),
        ], $ctx['headers'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'IMPORT_FILE_DUPLICATE')
            ->assertJsonPath('requires_confirmation', true);

        $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'confirm_duplicate_file' => true,
            'file' => $this->csvFile('sales-confirmed.csv', $rows),
        ], $ctx['headers'])->assertCreated();
    }

    public function test_active_previewed_batch_blocks_second_upload(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', [
                ['Ref', 'Customer'],
                ['INV-20260811-001', 'PT A'],
            ]),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => ['ref' => 'Ref', 'customer' => 'Customer'],
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('other.csv', [
                ['Ref', 'Customer'],
                ['INV-20260811-002', 'PT B'],
            ]),
        ], $ctx['headers'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'IMPORT_ACTIVE_BATCH_EXISTS')
            ->assertJsonPath('meta.batch_uuid', $batch['uuid']);
    }

    public function test_other_company_cannot_read_batch_and_cancel_deletes_file(): void
    {
        Storage::fake('local');
        $ctxA = $this->setUpTenant();

        $batch = $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', [
                ['Ref', 'Customer'],
                ['INV-20260811-001', 'PT A'],
            ]),
        ], $ctxA['headers'])->assertCreated()->json('data.batch');

        Storage::disk('local')->assertExists($batch['stored_path']);

        $ctxB = $this->setUpTenant();
        $this->getJson('/api/imports/'.$batch['uuid'], $ctxB['headers'])->assertNotFound();

        Sanctum::actingAs($ctxA['user'], ['*']);
        $this->getJson('/api/imports/'.$batch['uuid'], $ctxA['headers'])
            ->assertOk()
            ->assertJsonPath('data.uuid', $batch['uuid']);

        $this->deleteJson('/api/imports/'.$batch['uuid'], [], $ctxA['headers'])->assertOk();
        Storage::disk('local')->assertMissing($batch['stored_path']);
        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_template_endpoint_streams_csv(): void
    {
        $ctx = $this->setUpTenant();

        $this->getJson('/api/imports/templates/sales_invoice', $ctx['headers'])
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);

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
            'role' => $role,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $tenantPath = database_path('tenants/test_company_'.$company->id.'_'.uniqid().'.sqlite');
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
            'tenant_path' => $tenantPath,
        ];
    }

    private function csvFile(string $name, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_csv_');
        $handle = fopen($path, 'w');

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }

    private function xlsxFile(string $name, array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import_xlsx_');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile(
            $path,
            $name,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }
}
