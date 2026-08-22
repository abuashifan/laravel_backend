<?php

namespace Tests\Feature\Imports;

use App\Jobs\ImportBatchJob;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\ImportCommitterFactory;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class JournalEntryImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_balanced_journal_creates_draft_entry(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $batch = $this->createBatch($ctx, [
            ['Ref', 'Journal Date', 'Account Code', 'Description', 'Debit', 'Credit'],
            ['JV-001', '11/08/2026', '1100', 'Kas', '500000', '0'],
            ['JV-001', '11/08/2026', '4100', 'Pendapatan', '0', '500000'],
        ]);

        $this->dispatchSync($batch['uuid'], $ctx['company']->id);

        $batchModel = ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail();
        $this->assertSame('completed', $batchModel->status);
        $this->assertSame(2, $batchModel->committed_rows);

        $journal = JournalEntry::query()->firstOrFail();
        $this->assertSame('draft', $journal->status);
        $this->assertSame(2, $journal->lines()->count());
    }

    public function test_unbalanced_journal_is_rejected_at_preview(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $batch = $this->uploadCsv($ctx, [
            ['Ref', 'Journal Date', 'Account Code', 'Description', 'Debit', 'Credit'],
            ['JV-001', '11/08/2026', '1100', 'Kas', '500000', '0'],
            ['JV-001', '11/08/2026', '4100', 'Pendapatan', '0', '400000'],
        ]);

        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->journalMapping(),
        ], $ctx['headers'])->assertOk();

        // Komit gagal karena debit ≠ kredit — group-level validation di commitGroup.
        $batchModel = $this->dispatchSyncExpectingFailure($batch['uuid'], $ctx['company']->id);
        $this->assertSame('failed', $batchModel->status);
    }

    public function test_parent_account_is_rejected_in_preview(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        // 1100 adalah parent yang punya child (1101).
        $parent = ChartOfAccount::query()->where('account_code', '1100')->firstOrFail();
        ChartOfAccount::query()->create([
            'account_code' => '1101', 'account_name' => 'Kas Kecil', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'parent_account_id' => $parent->id, 'is_active' => true,
        ]);

        $batch = $this->uploadCsv($ctx, [
            ['Ref', 'Journal Date', 'Account Code', 'Description', 'Debit', 'Credit'],
            ['JV-001', '11/08/2026', '1100', 'Kas', '500000', '0'],
        ]);

        $res = $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->journalMapping(),
        ], $ctx['headers'])->assertOk();

        $res->assertJsonPath('data.failed_rows', 1);
        $errors = ImportRow::query()
            ->where('import_batch_id', ImportBatch::query()->where('uuid', $batch['uuid'])->firstOrFail()->id)
            ->firstOrFail()->errors;
        $this->assertStringContainsString('akun induk', $errors['account_code'][0]);
    }

    public function test_inactive_account_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        ChartOfAccount::query()->create([
            'account_code' => '9999', 'account_name' => 'Inaktif', 'account_type' => 'expense',
            'normal_balance' => 'debit', 'is_active' => false,
        ]);
        ChartOfAccount::query()->create([
            'account_code' => '4100', 'account_name' => 'Pendapatan', 'account_type' => 'revenue',
            'normal_balance' => 'credit', 'is_active' => true,
        ]);

        $batch = $this->uploadCsv($ctx, [
            ['Ref', 'Journal Date', 'Account Code', 'Description', 'Debit', 'Credit'],
            ['JV-001', '11/08/2026', '9999', 'Inaktif', '500000', '0'],
        ]);

        $res = $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->journalMapping(),
        ], $ctx['headers'])->assertOk();

        $res->assertJsonPath('data.failed_rows', 1);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAccounts(): void
    {
        ChartOfAccount::query()->create([
            'account_code' => '1100', 'account_name' => 'Kas', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'is_active' => true,
        ]);
        ChartOfAccount::query()->create([
            'account_code' => '4100', 'account_name' => 'Pendapatan', 'account_type' => 'revenue',
            'normal_balance' => 'credit', 'is_active' => true,
        ]);
    }

    private function journalMapping(): array
    {
        return [
            'ref' => 'Ref', 'journal_date' => 'Journal Date', 'account_code' => 'Account Code',
            'description' => 'Description', 'debit' => 'Debit', 'credit' => 'Credit',
        ];
    }

    private function createBatch(array $ctx, array $rows): array
    {
        $batch = $this->uploadCsv($ctx, $rows);
        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->journalMapping(),
        ], $ctx['headers'])->assertOk();

        return $batch;
    }

    private function uploadCsv(array $ctx, array $rows): array
    {
        return $this->postJson('/api/imports', [
            'profile' => 'journal_entry',
            'file' => $this->csvFile('journal.csv', $rows),
        ], $ctx['headers'])->assertCreated()->json('data.batch');
    }

    private function dispatchSync(string $uuid, int $companyId): void
    {
        // Meniru ImportBatchService::commit(): status di-flip ke 'committing'
        // sebelum job dijalankan.
        ImportBatch::query()->where('uuid', $uuid)->update(['status' => 'committing']);

        (new ImportBatchJob(['uuid' => $uuid, 'company_id' => $companyId]))
            ->handle(app(TenantConnectionManager::class), app(ImportCommitterFactory::class), app(TenantContext::class));
    }

    private function dispatchSyncExpectingFailure(string $uuid, int $companyId): ImportBatch
    {
        try {
            $this->dispatchSync($uuid, $companyId);
        } catch (\Throwable) {
        }

        return ImportBatch::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'JE Test '.$user->id, 'slug' => 'je-test-'.$user->id,
            'code' => 'JE-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $tenantPath = database_path('tenants/test_je_'.$company->id.'_'.uniqid().'.sqlite');
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
        $path = tempnam(sys_get_temp_dir(), 'je_csv_');
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
