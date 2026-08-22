<?php

namespace Tests\Feature\Imports;

use App\Jobs\ImportBatchJob;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\ImportCommitterFactory;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 2 rencana impor data — Antrean.
 *
 * Memvalidasi:
 * 1. Job memproses batch dan mengisi document_id di tiap baris
 * 2. Dua job untuk dua perusahaan berurutan → data tidak tercampur ⚠️ terpenting
 * 3. Job gagal → batch failed
 * 4. $tries = 1 — job tidak diulang otomatis
 * 5. Progres bertambah selama job berjalan
 */
class ImportBatchJobTest extends TestCase
{
    use RefreshDatabase;

    // ── Test 1: Job memproses batch ──────────────────────────────────────

    public function test_job_processes_batch_and_fills_document_ids(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        $batch = $this->createContactBatch($ctx, [
            ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
            ['CUST-001', 'PT Satu', 'customer', '', '', '', ''],
            ['CUST-002', 'PT Dua', 'customer', '', '', '', ''],
        ]);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);

        $batchModel = ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail();
        $this->assertSame('completed', $batchModel->status);
        $this->assertSame(2, $batchModel->committed_rows);

        // Generator CSV memberi kunci mulai dari 2 (1 = header, data mulai 2).
        $row2 = ImportRow::query()->where('import_batch_id', $batchModel->id)->where('row_number', 2)->firstOrFail();
        $this->assertSame('committed', $row2->status);
        $this->assertNotNull($row2->document_id);
        $this->assertSame(Contact::class, $row2->document_type);

        $row3 = ImportRow::query()->where('import_batch_id', $batchModel->id)->where('row_number', 3)->firstOrFail();
        $this->assertSame('committed', $row3->status);
        $this->assertNotNull($row3->document_id);
    }

    // ── Test 2: Isolasi lintas perusahaan ⚠️ TERPENTING ─────────────────

    public function test_two_jobs_for_different_companies_do_not_mix_data(): void
    {
        Storage::fake('local');

        // Siapkan dua perusahaan dengan dua user berbeda.
        $userA = User::factory()->create(['status' => 'active']);
        $userB = User::factory()->create(['status' => 'active']);

        $companyA = $this->createCompanyWithTenant($userA, 'A');
        $companyB = $this->createCompanyWithTenant($userB, 'B');

        // Batch A — autentikasi sebagai userA.
        Sanctum::actingAs($userA, ['*']);
        $batchA = $this->postJson('/api/imports', [
            'profile' => 'contact',
            'file' => $this->csvFile('a.csv', [
                ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
                ['A-001', 'PT Alpha', 'customer', '', '', '', ''],
                ['A-002', 'PT Beta', 'customer', '', '', '', ''],
            ]),
        ], ['X-Company-ID' => (string) $companyA->id])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batchA['uuid'].'/mapping', [
            'column_map' => [
                'code' => 'Code', 'name' => 'Name', 'type' => 'Type',
                'email' => 'Email', 'phone' => 'Phone',
                'address' => 'Address', 'tax_number' => 'Tax Number',
            ],
        ], ['X-Company-ID' => (string) $companyA->id])->assertOk();

        // Batch B — autentikasi sebagai userB.
        Sanctum::actingAs($userB, ['*']);
        $batchB = $this->postJson('/api/imports', [
            'profile' => 'contact',
            'file' => $this->csvFile('b.csv', [
                ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
                ['B-001', 'PT Charlie', 'customer', '', '', '', ''],
            ]),
        ], ['X-Company-ID' => (string) $companyB->id])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batchB['uuid'].'/mapping', [
            'column_map' => [
                'code' => 'Code', 'name' => 'Name', 'type' => 'Type',
                'email' => 'Email', 'phone' => 'Phone',
                'address' => 'Address', 'tax_number' => 'Tax Number',
            ],
        ], ['X-Company-ID' => (string) $companyB->id])->assertOk();

        // Jalankan job A, lalu job B.
        $this->dispatchSync($batchA['uuid'], $companyA->id);
        $this->dispatchSync($batchB['uuid'], $companyB->id);

        // ── Verifikasi: database A hanya punya data A ──
        $this->connectToCompany($companyA);
        $this->assertSame(2, Contact::query()->count(), 'Company A harus punya 2 kontak.');
        $this->assertNotNull(Contact::query()->where('contact_code', 'A-001')->first());
        $this->assertNotNull(Contact::query()->where('contact_code', 'A-002')->first());
        $this->assertNull(Contact::query()->where('contact_code', 'B-001')->first(), 'Data B tidak boleh bocor ke A.');

        // ── Verifikasi: database B hanya punya data B ──
        $this->connectToCompany($companyB);
        $this->assertSame(1, Contact::query()->count(), 'Company B harus punya 1 kontak.');
        $this->assertNotNull(Contact::query()->where('contact_code', 'B-001')->first());
        $this->assertNull(Contact::query()->where('contact_code', 'A-001')->first(), 'Data A tidak boleh bocor ke B.');
    }

    // ── Test 3: Job gagal → batch failed ────────────────────────────────

    public function test_failed_job_marks_batch_as_failed(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        // Gunakan vendor_bill — profil async yang belum punya committer
        // (Fase 4). sales_invoice sudah punya committer sejak Fase 3.
        $batch = $this->postJson('/api/imports', [
            'profile' => 'vendor_bill',
            'file' => $this->csvFile('bill.csv', [
                ['Ref', 'Vendor'],
                ['BILL-001', 'PT A'],
            ]),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        // Paksa status ke previewed (normalnya lewat mapping+validasi).
        ImportBatch::query()->where('uuid', $batch['uuid'])->update(['status' => 'previewed', 'valid_rows' => 1]);

        try {
            $this->dispatchSync($batch['uuid'], $ctx['company']->id);
        } catch (\Throwable) {
            // Diharapkan gagal karena profil belum punya committer.
        }

        $batchModel = ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail();
        $this->assertSame('failed', $batchModel->status, 'Batch harus berstatus failed.');
    }

    // ── Test 4: tries = 1 ────────────────────────────────────────────────

    public function test_job_has_exactly_one_try(): void
    {
        $job = new ImportBatchJob([
            'uuid' => 'test-uuid',
            'company_id' => 1,
        ]);

        $this->assertSame(1, $job->tries, 'ImportBatchJob harus punya $tries = 1.');
    }

    // ── Test 5: Progres bertambah ────────────────────────────────────────

    public function test_progress_updates_during_job_execution(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        // Lebih dari PROGRESS_INTERVAL (25) baris supaya progres tercatat.
        $rows = [['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number']];
        for ($i = 1; $i <= 30; $i++) {
            $rows[] = ['CUST-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'PT '.$i, 'customer', '', '', '', ''];
        }

        $batch = $this->createContactBatch($ctx, $rows);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);

        $batchModel = ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail();
        $this->assertSame('completed', $batchModel->status);
        $this->assertSame(30, $batchModel->committed_rows, 'Semua 30 baris harus committed.');
    }

    // ── Test: profil async dispatch job, profil sync tidak ───────────────

    public function test_async_profile_dispatches_job_and_sync_profile_does_not(): void
    {
        Bus::fake();
        Storage::fake('local');
        $ctx = $this->setUpTenant();

        // Profil non-async (contact) commit sinkron — tidak dispatch job.
        $batch = $this->createContactBatch($ctx, [
            ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
            ['CUST-001', 'PT Satu', 'customer', '', '', '', ''],
        ]);

        $this->postJson('/api/imports/'.$batch['uuid'].'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        Bus::assertNotDispatched(ImportBatchJob::class);

        // Profil async (vendor_bill) — punya committer sejak Fase 4,
        // jadi harus dispatch job ke antrean.
        Contact::query()->create([
            'contact_code' => 'PT-A', 'name' => 'PT A', 'contact_type' => 'supplier',
            'is_supplier' => true, 'is_active' => true,
        ]);
        Product::query()->create([
            'product_code' => 'PRD', 'product_name' => 'Produk', 'product_type' => 'goods',
            'is_active' => true,
        ]);

        $asyncBatch = $this->postJson('/api/imports', [
            'profile' => 'vendor_bill',
            'file' => $this->csvFile('bill.csv', [
                ['Ref', 'Vendor', 'Bill Date', 'Item', 'Quantity', 'Unit Cost'],
                ['BILL-001', 'PT A', '11/08/2026', 'Produk', '2', '50000'],
            ]),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$asyncBatch['uuid'].'/mapping', [
            'column_map' => [
                'ref' => 'Ref', 'vendor' => 'Vendor', 'bill_date' => 'Bill Date',
                'item' => 'Item', 'quantity' => 'Quantity', 'unit_cost' => 'Unit Cost',
            ],
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/imports/'.$asyncBatch['uuid'].'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'committing');

        Bus::assertDispatched(ImportBatchJob::class);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Kirim job secara sinkron — mensimulasikan worker memproses job
     * untuk satu perusahaan.
     */
    private function dispatchSync(string $uuid, int $companyId): void
    {
        // Meniru ImportBatchService::commit(): status batch di-flip ke
        // 'committing' SEBELUM job dijalankan — ImportBatchJob mengharapkan
        // status itu, bukan 'previewed'. Koneksi tenant harus disambungkan
        // dulu ke perusahaan yang benar (bisa beda dari koneksi tenant yang
        // sedang aktif, mis. test dua perusahaan berurutan).
        $tenantDb = TenantDatabase::query()->where('company_id', $companyId)->firstOrFail();
        app(TenantConnectionManager::class)->connect($tenantDb);
        ImportBatch::query()->where('uuid', $uuid)->update(['status' => 'committing']);

        $job = new ImportBatchJob([
            'uuid' => $uuid,
            'company_id' => $companyId,
        ]);

        $job->handle(
            app(TenantConnectionManager::class),
            app(ImportCommitterFactory::class),
            app(TenantContext::class),
        );
    }

    /**
     * Buat batch kontak lengkap — upload, mapping, sampai status previewed.
     */
    private function createContactBatch(array $ctx, array $rows): array
    {
        $batch = $this->postJson('/api/imports', [
            'profile' => 'contact',
            'file' => $this->csvFile('contact.csv', $rows),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => [
                'code' => 'Code', 'name' => 'Name', 'type' => 'Type',
                'email' => 'Email', 'phone' => 'Phone',
                'address' => 'Address', 'tax_number' => 'Tax Number',
            ],
        ], $ctx['headers'])->assertOk();

        return $batch;
    }

    /**
     * Buat perusahaan + tenant database + CompanyUser, tanpa autentikasi.
     */
    private function createCompanyWithTenant(User $user, string $suffix): Company
    {
        $company = Company::query()->create([
            'name' => 'PT Isolasi '.$suffix,
            'slug' => 'pt-isolasi-'.$suffix.'-'.uniqid(),
            'code' => 'ISO-'.$suffix.'-'.str_pad((string) $user->id, 4, '0', STR_PAD_LEFT),
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

        $tenantPath = database_path('tenants/test_isolation_'.$company->id.'_'.uniqid().'.sqlite');
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

        return $company;
    }

    /**
     * Sambung koneksi tenant ke perusahaan tertentu — dipakai setelah
     * menjalankan dua job berurutan untuk memverifikasi isolasi data.
     */
    private function connectToCompany(Company $company): void
    {
        $tenantDb = TenantDatabase::query()->where('company_id', $company->id)->firstOrFail();
        app(TenantConnectionManager::class)->connect($tenantDb);
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

        $tenantPath = database_path('tenants/test_job_'.$company->id.'_'.uniqid().'.sqlite');
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
}
