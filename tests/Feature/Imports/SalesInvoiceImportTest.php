<?php

namespace Tests\Feature\Imports;

use App\Jobs\ImportBatchJob;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\ImportCommitterFactory;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
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
 * Fase 3 rencana impor data — Profil Faktur Penjualan.
 *
 * Memvalidasi:
 * 1. Berkas valid → faktur draft terbentuk lewat SalesInvoiceService
 * 2. Multi-line grouping: 2 baris 1 ref → 1 faktur multi-baris
 * 3. Dua ref berbeda → dua faktur terpisah
 * 4. Group-level validation: tanggal/pelanggan berbeda dalam 1 ref
 * 5. Pelanggan/produk tidak dikenal → galat per baris
 * 6. external_ref duplikat → ditolak
 * 7. Draft guarantee (suppress_auto_post)
 * 8. Job async memproses batch
 */
class SalesInvoiceImportTest extends TestCase
{
    use RefreshDatabase;

    // ── Test 1: Berkas valid → faktur draft ───────────────────────────

    public function test_valid_file_creates_draft_invoice_through_service(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedSalesData();

        $batch = $this->createBatch($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Alpha', '2026-08-11', '2026-08-25', 'Kertas A4', '5', '50000', 'PPN', 'Catatan'],
        ]);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);

        $batchModel = ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail();
        $this->assertSame('completed', $batchModel->status);
        $this->assertSame(1, $batchModel->committed_rows);

        $invoice = SalesInvoice::query()->firstOrFail();
        $this->assertSame('draft', $invoice->status);
        $this->assertSame('PT Alpha', $invoice->customer->name);
        $this->assertSame(1, $invoice->lines()->count());
        $this->assertSame(5.0, (float) $invoice->lines()->first()->quantity);

        $row = ImportRow::query()->where('import_batch_id', $batchModel->id)->firstOrFail();
        $this->assertSame('committed', $row->status);
        $this->assertSame($invoice->id, $row->document_id);
        $this->assertSame(SalesInvoice::class, $row->document_type);
    }

    // ── Test 2: Multi-line grouping ───────────────────────────────────

    public function test_two_rows_same_ref_create_one_invoice_with_two_lines(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedSalesData();

        $batch = $this->createBatch($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Alpha', '2026-08-11', '2026-08-25', 'Kertas A4', '5', '50000', '', ''],
            ['INV-001', 'PT Alpha', '2026-08-11', '2026-08-25', 'Pulpen', '10', '3500', '', ''],
        ]);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);

        $this->assertSame(1, SalesInvoice::query()->count(), 'Harusnya 1 faktur.');
        $invoice = SalesInvoice::query()->firstOrFail();
        $this->assertSame(2, $invoice->lines()->count(), 'Harusnya 2 baris barang.');

        $rows = ImportRow::query()->where('import_batch_id', ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail()->id)
            ->orderBy('row_number')->get();
        $this->assertSame('committed', $rows[0]->status);
        $this->assertSame('committed', $rows[1]->status);
        $this->assertSame($rows[0]->document_id, $rows[1]->document_id, 'Kedua baris harus punya document_id yang sama.');
    }

    // ── Test 3: Dua ref berbeda → dua faktur ──────────────────────────

    public function test_two_different_refs_create_two_separate_invoices(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedSalesData();

        $batch = $this->createBatch($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Alpha', '2026-08-11', '', 'Kertas A4', '1', '50000', '', ''],
            ['INV-002', 'PT Alpha', '2026-08-12', '', 'Pulpen', '2', '3500', '', ''],
        ]);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);

        $this->assertSame(2, SalesInvoice::query()->count());
    }

    // ── Test 4: Group-level — tanggal berbeda ─────────────────────────

    public function test_same_ref_with_different_dates_is_invalid_at_preview(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedSalesData();

        $batch = $this->uploadCsv($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Alpha', '2026-08-11', '', 'Kertas A4', '1', '50000', '', ''],
            ['INV-001', 'PT Alpha', '2026-08-12', '', 'Pulpen', '2', '3500', '', ''],
        ]);

        $res = $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->salesInvoiceMapping(),
        ], $ctx['headers'])->assertOk();

        $res->assertJsonPath('data.failed_rows', 1);
        $res->assertJsonPath('data.valid_rows', 1);

        $invalidRow = ImportRow::query()
            ->where('import_batch_id', ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail()->id)
            ->where('status', 'invalid')
            ->firstOrFail();
        $this->assertStringContainsString('tanggal berbeda', $invalidRow->errors['ref'][0]);
    }

    // ── Test 5: Group-level — pelanggan berbeda ───────────────────────

    public function test_same_ref_with_different_customer_is_invalid_at_preview(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedSalesData();
        Contact::query()->create([
            'contact_code' => 'PT-BETA', 'name' => 'PT Beta', 'contact_type' => 'customer',
            'is_customer' => true, 'is_active' => true,
        ]);

        $batch = $this->uploadCsv($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Alpha', '2026-08-11', '', 'Kertas A4', '1', '50000', '', ''],
            ['INV-001', 'PT Beta', '2026-08-11', '', 'Pulpen', '2', '3500', '', ''],
        ]);

        $res = $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->salesInvoiceMapping(),
        ], $ctx['headers'])->assertOk();

        $res->assertJsonPath('data.failed_rows', 1);
        $res->assertJsonPath('data.valid_rows', 1);
    }

    // ── Test 6: Pelanggan/produk tidak dikenal ────────────────────────

    public function test_unknown_customer_and_product_report_values_in_error(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->uploadCsv($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Fiktif', '2026-08-11', '', 'Produk Fiktif', '1', '50000', '', ''],
        ]);

        $res = $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->salesInvoiceMapping(),
        ], $ctx['headers'])->assertOk();

        $res->assertJsonPath('data.failed_rows', 1);

        $errors = ImportRow::query()
            ->where('import_batch_id', ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail()->id)
            ->firstOrFail()->errors;
        $this->assertStringContainsString('PT Fiktif', $errors['customer'][0]);
        $this->assertStringContainsString('Produk Fiktif', $errors['item'][0]);
    }

    // ── Test 7: external_ref duplikat ─────────────────────────────────

    public function test_duplicate_external_ref_from_previous_batch_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedSalesData();

        $first = $this->createBatch($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Alpha', '2026-08-11', '', 'Kertas A4', '1', '50000', '', ''],
        ]);
        ImportBatch::query()->where('uuid', $first['uuid'])->update(['status' => 'completed']);

        // Berkas berbeda (baris beda) supaya file_hash tidak bentrok,
        // tapi external_ref sama → harus ditolak di validasi.
        $second = $this->uploadCsv($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Alpha', '2026-08-11', '', 'Pulpen', '2', '3500', '', ''],
        ]);

        $res = $this->patchJson('/api/imports/'.$second['uuid'].'/mapping', [
            'column_map' => $this->salesInvoiceMapping(),
        ], $ctx['headers'])->assertOk();

        $res->assertJsonPath('data.valid_rows', 0);
        $res->assertJsonPath('data.failed_rows', 1);
    }

    // ── Test 8: Draft guarantee ───────────────────────────────────────

    public function test_invoice_is_always_draft_even_with_auto_post_workflow(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedSalesData();

        $batch = $this->createBatch($ctx, [
            ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            ['INV-001', 'PT Alpha', '2026-08-11', '', 'Kertas A4', '1', '50000', '', ''],
        ]);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);

        $invoice = SalesInvoice::query()->firstOrFail();
        $this->assertSame('draft', $invoice->status, 'Faktur impor harus selalu draft.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedSalesData(): void
    {
        Contact::query()->create([
            'contact_code' => 'PT-ALPHA', 'name' => 'PT Alpha', 'contact_type' => 'customer',
            'is_customer' => true, 'is_active' => true,
        ]);
        Product::query()->create([
            'product_code' => 'KERTAS-A4', 'product_name' => 'Kertas A4', 'product_type' => 'goods',
            'is_active' => true,
        ]);
        Product::query()->create([
            'product_code' => 'PULPEN', 'product_name' => 'Pulpen', 'product_type' => 'goods',
            'is_active' => true,
        ]);
    }

    private function salesInvoiceMapping(): array
    {
        return [
            'ref' => 'Ref', 'customer' => 'Customer', 'invoice_date' => 'Invoice Date',
            'due_date' => 'Due Date', 'item' => 'Item', 'quantity' => 'Quantity',
            'unit_price' => 'Unit Price', 'tax_code' => 'Tax Code', 'notes' => 'Notes',
        ];
    }

    private function createBatch(array $ctx, array $rows): array
    {
        $batch = $this->uploadCsv($ctx, $rows);
        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->salesInvoiceMapping(),
        ], $ctx['headers'])->assertOk();

        return $batch;
    }

    private function uploadCsv(array $ctx, array $rows): array
    {
        return $this->postJson('/api/imports', [
            'profile' => 'sales_invoice',
            'file' => $this->csvFile('sales.csv', $rows),
        ], $ctx['headers'])->assertCreated()->json('data.batch');
    }

    private function dispatchSync(string $uuid, int $companyId): void
    {
        $job = new ImportBatchJob(['uuid' => $uuid, 'company_id' => $companyId]);
        $job->handle(app(TenantConnectionManager::class), app(ImportCommitterFactory::class));
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'SI Test '.$user->id, 'slug' => 'si-test-'.$user->id,
            'code' => 'SI-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);

        $tenantPath = database_path('tenants/test_si_'.$company->id.'_'.uniqid().'.sqlite');
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
        $path = tempnam(sys_get_temp_dir(), 'import_csv_');
        $handle = fopen($path, 'w');
        foreach ($rows as $row) { fputcsv($handle, $row); }
        fclose($handle);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
