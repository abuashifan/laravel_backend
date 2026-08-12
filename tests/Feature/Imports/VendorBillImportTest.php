<?php

namespace Tests\Feature\Imports;

use App\Jobs\ImportBatchJob;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\ImportCommitterFactory;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\Purchase\Models\VendorBill;
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

class VendorBillImportTest extends TestCase
{
    use RefreshDatabase;

    private int $warehouseId;

    public function test_valid_file_creates_draft_bill(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedData();

        $batch = $this->createBatch($ctx, [
            ['Ref', 'Vendor', 'Bill Date', 'Item', 'Quantity', 'Unit Cost', 'Warehouse'],
            ['BILL-001', 'PT Supplier', '2026-08-11', 'Barang A', '5', '75000', (string) $this->warehouseId],
        ]);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);

        $batchModel = ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail();
        $this->assertSame('completed', $batchModel->status);
        $this->assertSame(1, VendorBill::query()->count());
        $this->assertSame('draft', VendorBill::query()->firstOrFail()->status);
        $this->assertSame('PT Supplier', VendorBill::query()->firstOrFail()->vendor->name);
    }

    public function test_two_rows_same_ref_one_bill_two_lines(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedData();

        $batch = $this->createBatch($ctx, [
            ['Ref', 'Vendor', 'Bill Date', 'Item', 'Quantity', 'Unit Cost', 'Warehouse'],
            ['BILL-001', 'PT Supplier', '2026-08-11', 'Barang A', '3', '50000', (string) $this->warehouseId],
            ['BILL-001', 'PT Supplier', '2026-08-11', 'Barang B', '2', '75000', (string) $this->warehouseId],
        ]);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);
        $this->assertSame(1, VendorBill::query()->count());
        $this->assertSame(2, VendorBill::query()->firstOrFail()->lines()->count());
    }

    public function test_unknown_vendor_reports_error(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->uploadCsv($ctx, [
            ['Ref', 'Vendor', 'Bill Date', 'Item', 'Quantity', 'Unit Cost'],
            ['BILL-001', 'PT Fiktif', '2026-08-11', 'Barang A', '1', '50000'],
        ]);

        $res = $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => ['ref' => 'Ref', 'vendor' => 'Vendor', 'bill_date' => 'Bill Date', 'item' => 'Item', 'quantity' => 'Quantity', 'unit_cost' => 'Unit Cost'],
        ], $ctx['headers'])->assertOk();

        $res->assertJsonPath('data.failed_rows', 1);
        $errors = ImportRow::query()
            ->where('import_batch_id', ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail()->id)
            ->firstOrFail()->errors;
        $this->assertStringContainsString('PT Fiktif', $errors['vendor'][0]);
    }

    public function test_same_ref_different_vendor_is_invalid(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedData();
        Contact::query()->create([
            'contact_code' => 'PT-OTHER', 'name' => 'PT Other', 'contact_type' => 'supplier',
            'is_supplier' => true, 'is_active' => true,
        ]);

        $batch = $this->uploadCsv($ctx, [
            ['Ref', 'Vendor', 'Bill Date', 'Item', 'Quantity', 'Unit Cost', 'Warehouse'],
            ['BILL-001', 'PT Supplier', '2026-08-11', 'Barang A', '1', '50000', (string) $this->warehouseId],
            ['BILL-001', 'PT Other', '2026-08-11', 'Barang B', '2', '75000', (string) $this->warehouseId],
        ]);

        $res = $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->mapping(),
        ], $ctx['headers'])->assertOk();

        $res->assertJsonPath('data.failed_rows', 1);
        $res->assertJsonPath('data.valid_rows', 1);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedData(): void
    {
        Contact::query()->create([
            'contact_code' => 'PT-SUPPLIER', 'name' => 'PT Supplier', 'contact_type' => 'supplier',
            'is_supplier' => true, 'is_active' => true,
        ]);
        $unit = \App\Modules\MasterData\Models\Unit::query()->create([
            'code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true,
        ]);
        $wh = \App\Modules\MasterData\Models\Warehouse::query()->create([
            'code' => 'WH1', 'name' => 'Gudang Utama', 'is_active' => true,
        ]);
        $this->warehouseId = (int) $wh->id;
        Product::query()->create([
            'product_code' => 'BRG-A', 'product_name' => 'Barang A', 'product_type' => 'goods',
            'unit_id' => $unit->id, 'is_active' => true, 'is_stock_item' => true,
        ]);
        Product::query()->create([
            'product_code' => 'BRG-B', 'product_name' => 'Barang B', 'product_type' => 'goods',
            'unit_id' => $unit->id, 'is_active' => true, 'is_stock_item' => true,
        ]);
    }

    private function mapping(): array
    {
        return [
            'ref' => 'Ref', 'vendor' => 'Vendor', 'bill_date' => 'Bill Date',
            'item' => 'Item', 'quantity' => 'Quantity', 'unit_cost' => 'Unit Cost',
            'warehouse_id' => 'Warehouse',
        ];
    }

    private function createBatch(array $ctx, array $rows): array
    {
        $batch = $this->uploadCsv($ctx, $rows);
        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->mapping(),
        ], $ctx['headers'])->assertOk();

        return $batch;
    }

    private function uploadCsv(array $ctx, array $rows): array
    {
        return $this->postJson('/api/imports', [
            'profile' => 'vendor_bill',
            'file' => $this->csvFile('bill.csv', $rows),
        ], $ctx['headers'])->assertCreated()->json('data.batch');
    }

    private function dispatchSync(string $uuid, int $companyId): void
    {
        (new ImportBatchJob(['uuid' => $uuid, 'company_id' => $companyId]))
            ->handle(app(TenantConnectionManager::class), app(ImportCommitterFactory::class));
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'VB '.$user->id, 'slug' => 'vb-'.$user->id,
            'code' => 'VB-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $tenantPath = database_path('tenants/vb_'.$company->id.'_'.uniqid().'.sqlite');
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
        $path = tempnam(sys_get_temp_dir(), 'vb_');
        $h = fopen($path, 'w');
        foreach ($rows as $r) { fputcsv($h, $r); }
        fclose($h);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
