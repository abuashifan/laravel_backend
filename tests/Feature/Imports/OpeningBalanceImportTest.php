<?php

namespace Tests\Feature\Imports;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Models\Company;
use App\Shared\Models\CompanySetupState;
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
 * Profil impor saldo awal — Fase 7 rencana impor data.
 */
class OpeningBalanceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_rows_become_draft_opening_balance_lines(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
            ['3100', 'Modal disetor', '0', '5000000'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 2);

        $batch = OpeningBalanceBatch::query()->firstOrFail();
        // Impor mengisi draft saja — user tetap menekan Validasi & Posting sendiri.
        $this->assertSame('draft', $batch->status);
        $this->assertSame(2, $batch->lines()->count());
        $this->assertEqualsWithDelta(5000000, (float) $batch->total_debit, 0.001);
        $this->assertEqualsWithDelta(5000000, (float) $batch->total_credit, 0.001);
        $this->assertSame('opening_balance_import', $batch->lines()->first()->source_type);
    }

    public function test_existing_manual_lines_are_preserved_not_replaced(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $accounts = $this->seedAccounts();

        // User sudah mengetik satu baris manual di halaman Saldo Awal.
        $created = $this->postJson('/api/opening-balance/batches', [
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertCreated()->json('data');

        $this->putJson('/api/opening-balance/batches/'.$created['id'].'/lines', [
            'lines' => [['account_id' => $accounts['bank'], 'debit' => 2000000, 'credit' => 0, 'description' => 'Diketik manual']],
        ], $ctx['headers'])->assertOk();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 1);

        // Keputusan 7B-1: impor MENGGABUNG. `replaceLines()` menghapus semua
        // baris sebelum insert, jadi tanpa baca-gabung-tulis baris manual ini
        // akan hilang tanpa jejak.
        $batch = OpeningBalanceBatch::query()->findOrFail($created['id']);
        $this->assertSame(2, $batch->lines()->count());
        $this->assertTrue($batch->lines()->where('description', 'Diketik manual')->exists());
    }

    public function test_account_already_in_batch_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $accounts = $this->seedAccounts();

        $created = $this->postJson('/api/opening-balance/batches', [
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertCreated()->json('data');

        $this->putJson('/api/opening-balance/batches/'.$created['id'].'/lines', [
            'lines' => [['account_id' => $accounts['cash'], 'debit' => 2000000, 'credit' => 0]],
        ], $ctx['headers'])->assertOk();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
        ], expectedFailedRows: 1);

        $this->assertStringContainsString('sudah punya baris saldo awal', $this->firstRowErrors($uuid)['account_code'][0]);
    }

    public function test_parent_account_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $accounts = $this->seedAccounts();

        ChartOfAccount::query()->create([
            'account_code' => '1101.1', 'account_name' => 'Kas Kecil', 'account_type' => 'asset',
            'normal_balance' => 'debit', 'parent_account_id' => $accounts['cash'], 'is_active' => true,
        ]);

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
        ], expectedFailedRows: 1);

        $this->assertStringContainsString('akun induk', $this->firstRowErrors($uuid)['account_code'][0]);
    }

    public function test_fixed_asset_control_account_is_rejected_with_guidance(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1530', 'Peralatan', '10000000', '0'],
        ], expectedFailedRows: 1);

        // Akun ini dihasilkan otomatis oleh fixedAssetSystemLines(); baris manual
        // dengan akun sama membuat batch ditolak FIXED_ASSET_CONTROL_DUPLICATE
        // dengan pesan yang tidak menyebut baris mana. Ditangkap lebih awal.
        $message = $this->firstRowErrors($uuid)['account_code'][0];
        $this->assertStringContainsString('akun kontrol aset tetap', $message);
        $this->assertStringContainsString('profil Aset Tetap Awal', $message);
    }

    public function test_duplicate_account_within_file_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
            ['1101', 'Saldo awal kas lagi', '1000000', '0'],
        ], expectedFailedRows: 1);

        // Baris data pertama ada di row_number 2 (baris 1 = header), jadi yang
        // ditandai duplikat adalah row_number 3. Dicari lewat status supaya
        // test tidak pecah kalau penomoran baris pembaca berubah.
        $batchId = ImportBatch::query()->where('uuid', $uuid)->firstOrFail()->id;
        $invalid = ImportRow::query()->where('import_batch_id', $batchId)->where('status', 'invalid')->firstOrFail();
        $this->assertSame(3, (int) $invalid->row_number);
        $this->assertStringContainsString('lebih dari sekali', ((array) $invalid->errors)['account_code'][0]);
    }

    public function test_row_with_both_debit_and_credit_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Dua sisi', '5000000', '5000000'],
        ], expectedFailedRows: 1);

        $this->assertArrayHasKey('debit', $this->firstRowErrors($uuid));
    }

    public function test_import_is_blocked_when_opening_balance_already_posted(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $accounts = $this->seedAccounts();

        $created = $this->postJson('/api/opening-balance/batches', [
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertCreated()->json('data');

        $this->putJson('/api/opening-balance/batches/'.$created['id'].'/lines', [
            'lines' => [
                ['account_id' => $accounts['cash'], 'debit' => 1000000, 'credit' => 0],
                ['account_id' => $accounts['equity'], 'debit' => 0, 'credit' => 1000000],
            ],
        ], $ctx['headers'])->assertOk();
        $this->postJson('/api/opening-balance/batches/'.$created['id'].'/validate', [], $ctx['headers'])->assertOk();
        $this->postJson('/api/opening-balance/batches/'.$created['id'].'/post', [], $ctx['headers'])->assertOk();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1102', 'Saldo awal bank', '5000000', '0'],
        ], expectedFailedRows: 1);

        $this->assertStringContainsString('sudah diposting', $this->firstRowErrors($uuid)['account_code'][0]);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Urutan wajib: aset tetap awal DULU, saldo awal belakangan.
     *
     * Baris harga perolehan & akumulasi penyusutan di batch saldo awal lahir
     * otomatis dari register aset, jadi berkas saldo awal yang masuk lebih dulu
     * akan mengunci neraca pembuka pada angka tanpa aset -- dan impor asetnya
     * ditolak begitu saldo awal diposting. Ditolak di titik paling awal yang
     * masih bisa diperbaiki tanpa reopen.
     */
    public function test_import_is_blocked_when_fixed_assets_enabled_but_opening_assets_missing(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();
        app(CompanySettingService::class)->updateModuleSetting($ctx['company'], ['fixed_asset_enabled' => true]);

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
            ['3100', 'Modal disetor', '0', '5000000'],
        ], expectedFailedRows: 2);

        $errors = $this->firstRowErrors($uuid);
        $this->assertStringContainsString('aset tetap awal belum diimpor', $errors['account_code'][0]);

        // Tidak ada baris valid sama sekali -- commit ditolak, batch saldo awal
        // tidak pernah dibuat.
        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])->assertStatus(422);
        $this->assertSame(0, OpeningBalanceBatch::query()->count());
    }

    public function test_import_proceeds_once_opening_fixed_assets_are_registered(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();
        app(CompanySettingService::class)->updateModuleSetting($ctx['company'], ['fixed_asset_enabled' => true]);
        $this->registerOpeningAsset();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 1);
    }

    /**
     * Jalan keluar kedua, sama persis dengan yang diterima
     * `SetupWizardService::validateOpeningFixedAssets()`: perusahaan yang modul
     * Aktiva Tetapnya aktif tapi memang tidak punya aset warisan. Tanpa jalur
     * ini, mengaktifkan modul akan mengunci impor saldo awal selamanya.
     */
    public function test_import_proceeds_when_absence_of_opening_fixed_assets_is_confirmed(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();
        app(CompanySettingService::class)->updateModuleSetting($ctx['company'], ['fixed_asset_enabled' => true]);

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_fixed_assets',
            'confirm_no_opening_fixed_assets' => true,
        ], $ctx['headers'])->assertOk();

        $this->assertTrue(
            (bool) ((array) CompanySetupState::query()->where('company_id', $ctx['company']->id)->firstOrFail()->metadata)['opening_fixed_assets_confirmed_none']
        );

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 1);
    }

    /** Modul Aktiva Tetap mati -- gerbang urutan tidak berlaku sama sekali. */
    public function test_import_is_unaffected_when_fixed_asset_module_is_disabled(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '5000000', '0'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 1);
    }

    /**
     * Satu baris register aset warisan. Ditulis langsung ke model, bukan lewat
     * impor aset: yang diuji di kelas ini adalah gerbangnya, bukan committer
     * aset tetap (itu punya FixedAssetOpeningImportTest sendiri).
     */
    private function registerOpeningAsset(): void
    {
        $category = FixedAssetCategory::query()->where('code', 'IT_EQUIP')->firstOrFail();

        FixedAsset::query()->create([
            'name' => 'Laptop Warisan',
            'fixed_asset_category_id' => $category->id,
            'asset_class' => $category->asset_class,
            'depreciation_type' => $category->depreciation_type,
            'status' => 'draft',
            'acquisition_date' => '2024-07-01',
            'acquisition_cost' => 18000000,
            'accumulated_depreciation' => 4500000,
            'net_book_value' => 13500000,
            'source_type' => 'opening_import',
        ]);
    }

    private function uploadAndMap(array $ctx, array $rows, int $expectedFailedRows = 0): string
    {
        $batch = $this->postJson('/api/imports', [
            'profile' => 'opening_balance',
            'file' => $this->csvFile('ob.csv', $rows),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => [
                'account_code' => 'Account Code',
                'description' => 'Description',
                'debit' => 'Debit',
                'credit' => 'Credit',
            ],
        ], $ctx['headers'])->assertOk()->assertJsonPath('data.failed_rows', $expectedFailedRows);

        return $batch['uuid'];
    }

    private function firstRowErrors(string $uuid): array
    {
        $batchId = ImportBatch::query()->where('uuid', $uuid)->firstOrFail()->id;

        return (array) ImportRow::query()->where('import_batch_id', $batchId)->firstOrFail()->errors;
    }

    /**
     * @return array<string, int>
     */
    private function seedAccounts(): array
    {
        $cash = $this->account('1101', 'Kas', 'asset', 'debit');
        $bank = $this->account('1102', 'Bank', 'asset', 'debit');
        $equity = $this->account('3100', 'Modal Disetor', 'equity', 'credit');
        $faCost = $this->account('1530', 'Peralatan', 'asset', 'debit');
        $faAccumulated = $this->account('1531', 'Akumulasi Penyusutan Peralatan', 'asset', 'credit');

        foreach ([
            'opening_balance.equity' => ['opening_balance', $equity],
            'fixed_assets.cost' => ['fixed_assets', $faCost],
            'fixed_assets.equipment_accumulated_depreciation' => ['fixed_assets', $faAccumulated],
        ] as $key => [$module, $accountId]) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                ['module' => $module, 'account_id' => $accountId, 'is_required' => true, 'is_active' => true],
            );
        }

        return ['cash' => $cash, 'bank' => $bank, 'equity' => $equity, 'fa_cost' => $faCost];
    }

    private function account(string $code, string $name, string $type, string $normalBalance): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'is_active' => true,
        ])->id;
    }

    private function setUpTenant(string $role = 'owner'): array
    {
        $user = User::factory()->create(['status' => 'active']);
        $company = Company::query()->create([
            'name' => 'OB Import '.$user->id, 'slug' => 'ob-import-'.$user->id,
            'code' => 'OBI-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $tenantPath = database_path('tenants/test_obi_'.$company->id.'_'.uniqid().'.sqlite');
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
        $path = tempnam(sys_get_temp_dir(), 'obi_csv_');
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
