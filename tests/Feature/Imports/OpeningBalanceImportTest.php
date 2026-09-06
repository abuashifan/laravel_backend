<?php

namespace Tests\Feature\Imports;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
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
 * Profil impor saldo awal — Fase 8.
 *
 * Yang diuji di sini adalah tiga janji Fase 8: berkas jadi satu jurnal yang
 * seimbang sendiri lewat akun perantara, akun aset tetap tidak lagi ditolak,
 * dan tiap berkas bisa dibatalkan sendiri.
 */
class OpeningBalanceImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_file_becomes_one_posted_journal_balanced_by_the_clearing_account(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $accounts = $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '10000000', '0'],
            ['2100', 'Utang usaha', '0', '4000000'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 2);

        $journal = JournalEntry::query()->where('source_module', 'opening_balance')->firstOrFail();
        $this->assertSame('posted', $journal->status);

        // Dua baris berkas + satu baris perantara yang dihitung sistem.
        $this->assertSame(3, $journal->lines()->count());
        $this->assertEqualsWithDelta(10000000, (float) $journal->lines()->sum('debit'), 0.001);
        $this->assertEqualsWithDelta(10000000, (float) $journal->lines()->sum('credit'), 0.001);

        $clearingLine = $journal->lines()->where('account_id', $accounts['clearing'])->firstOrFail();
        $this->assertEqualsWithDelta(6000000, (float) $clearingLine->credit, 0.001);

        $status = $this->getJson('/api/opening-balance/status', $ctx['headers'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(-6000000, (float) $status['clearing_balance'], 0.001);
        $this->assertSame(1, $status['journal_count']);
    }

    /**
     * Berkas boleh dicicil: kas hari ini, piutang besok. Tiap berkas jadi
     * jurnalnya sendiri, dan saldo perantara terakumulasi.
     */
    public function test_second_file_posts_its_own_journal(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        foreach ([['1101', '10000000'], ['1102', '5000000']] as [$code, $amount]) {
            $uuid = $this->uploadAndMap($ctx, [
                ['Account Code', 'Description', 'Debit', 'Credit'],
                [$code, 'Saldo awal', $amount, '0'],
            ], filename: 'ob-'.$code.'.csv');

            $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])->assertOk();
        }

        $this->assertSame(2, JournalEntry::query()->where('source_module', 'opening_balance')->count());
        $status = $this->getJson('/api/opening-balance/status', $ctx['headers'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(-15000000, (float) $status['clearing_balance'], 0.001);
    }

    /**
     * Regresi TERBALIK dari Fase 7: akun harga perolehan aset tetap dulu ditolak
     * per baris karena baris kontrolnya dihasilkan otomatis dari register.
     * Sejak Fase 8 register tidak lagi menyentuh buku besar, jadi akunnya diisi
     * lewat berkas ini seperti akun lain.
     */
    public function test_fixed_asset_accounts_are_accepted(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1530', 'Peralatan', '18000000', '0'],
            ['1531', 'Akumulasi penyusutan peralatan', '0', '4500000'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 2);
    }

    /**
     * Urutan bebas — inti Fase 8. Aset didaftarkan lebih dulu, saldo awal
     * menyusul, dan tidak ada satu pun penolakan.
     */
    public function test_import_order_does_not_matter(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();
        $this->registerOpeningAsset();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1530', 'Peralatan', '18000000', '0'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 1);
    }

    public function test_nominal_and_parent_accounts_are_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $parent = $this->account('1', 'AKTIVA', 'asset', 'debit');
        $this->account('1199', 'Kas Lain', 'asset', 'debit', parentId: $parent);
        $this->account('4100', 'Pendapatan', 'revenue', 'credit');

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1', 'Akun induk', '1000', '0'],
            ['4100', 'Pendapatan', '0', '1000'],
        ], expectedFailedRows: 2);

        $errors = $this->rowErrors($uuid);
        $this->assertStringContainsString('akun induk', $errors[0]['account_code'][0]);
        $this->assertStringContainsString('nominal', $errors[1]['account_code'][0]);
    }

    public function test_duplicate_account_in_the_same_file_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Kas', '1000', '0'],
            ['1101', 'Kas lagi', '2000', '0'],
        ], expectedFailedRows: 1);

        $this->assertStringContainsString('lebih dari sekali', $this->rowErrors($uuid)[1]['account_code'][0]);
    }

    /**
     * Pembatalan: jurnalnya di-void, saldo perantara kembali, dan batch impornya
     * tetap tersimpan sebagai riwayat.
     */
    public function test_revert_voids_the_journal_and_restores_the_clearing_balance(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '10000000', '0'],
        ]);
        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])->assertOk();

        $this->postJson('/api/imports/'.$uuid.'/revert', ['reason' => 'Angka kasnya salah'], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'reverted')
            ->assertJsonPath('data.committed_rows', 0);

        $this->assertSame('void', JournalEntry::query()->where('source_module', 'opening_balance')->firstOrFail()->status);
        $status = $this->getJson('/api/opening-balance/status', $ctx['headers'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(0, (float) $status['clearing_balance'], 0.001);
        $this->assertSame(0, $status['journal_count']);

        // Riwayatnya tetap ada — justru itu yang dibutuhkan saat ada yang salah.
        $this->getJson('/api/imports?page=1&per_page=25', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.data.0.status', 'reverted')
            ->assertJsonPath('data.total', 1);
    }

    /**
     * Berkas yang sama boleh diunggah ulang setelah dibatalkan tanpa dihadang
     * peringatan duplikat — itulah jalur perbaikan yang normal.
     */
    public function test_same_file_can_be_re_uploaded_after_revert(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $rows = [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '10000000', '0'],
        ];

        $uuid = $this->uploadAndMap($ctx, $rows);
        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])->assertOk();
        $this->postJson('/api/imports/'.$uuid.'/revert', ['reason' => 'salah angka'], $ctx['headers'])->assertOk();

        $this->postJson('/api/imports', [
            'profile' => 'opening_balance',
            'file' => $this->csvFile('ob.csv', $rows),
        ], $ctx['headers'])->assertCreated()->assertJsonPath('data.duplicate_file', null);
    }

    public function test_committed_batch_cannot_be_cancelled(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '10000000', '0'],
        ]);
        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])->assertOk();

        $this->deleteJson('/api/imports/'.$uuid, [], $ctx['headers'])->assertStatus(422);
    }

    /**
     * Penutupan perantara: modal pemilik tidak diketik, ia sisa perantara.
     */
    public function test_closing_the_clearing_account_moves_the_balance_to_equity(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $accounts = $this->seedAccounts();

        $uuid = $this->uploadAndMap($ctx, [
            ['Account Code', 'Description', 'Debit', 'Credit'],
            ['1101', 'Saldo awal kas', '10000000', '0'],
            ['2100', 'Utang usaha', '0', '4000000'],
        ]);
        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])->assertOk();

        $this->postJson('/api/opening-balance/close-clearing', [], $ctx['headers'])->assertOk();

        $status = $this->getJson('/api/opening-balance/status', $ctx['headers'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(0, (float) $status['clearing_balance'], 0.001);
        $this->assertTrue($status['is_complete']);

        $equityCredit = (float) \DB::connection('tenant')->table('journal_entry_lines')
            ->where('account_id', $accounts['equity'])
            ->sum('credit');
        $this->assertEqualsWithDelta(6000000, $equityCredit, 0.001);

        // Sudah nol — tidak ada lagi yang perlu ditutup.
        $this->postJson('/api/opening-balance/close-clearing', [], $ctx['headers'])->assertStatus(422);
    }

    /**
     * Satu baris register aset warisan, ditulis langsung ke model: yang diuji di
     * kelas ini adalah profil saldo awal, bukan committer aset tetap.
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

    private function uploadAndMap(array $ctx, array $rows, int $expectedFailedRows = 0, string $filename = 'ob.csv'): string
    {
        $batch = $this->postJson('/api/imports', [
            'profile' => 'opening_balance',
            'file' => $this->csvFile($filename, $rows),
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

    /**
     * @return array<int, array<string, list<string>>>
     */
    private function rowErrors(string $uuid): array
    {
        $batchId = ImportBatch::query()->where('uuid', $uuid)->firstOrFail()->id;

        return ImportRow::query()
            ->where('import_batch_id', $batchId)
            ->orderBy('row_number')
            ->get()
            ->map(fn (ImportRow $row): array => (array) $row->errors)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function seedAccounts(): array
    {
        $cash = $this->account('1101', 'Kas', 'asset', 'debit');
        $bank = $this->account('1102', 'Bank', 'asset', 'debit');
        $payable = $this->account('2100', 'Utang Usaha', 'liability', 'credit');
        $equity = $this->account('3100', 'Modal Disetor', 'equity', 'credit');
        $clearing = $this->account('3900', 'Saldo Awal (Perantara)', 'equity', 'credit');
        $faCost = $this->account('1530', 'Peralatan', 'asset', 'debit');
        $faAccumulated = $this->account('1531', 'Akumulasi Penyusutan Peralatan', 'asset', 'credit');

        foreach ([
            'opening_balance.equity' => ['opening_balance', $equity],
            'opening_balance.clearing' => ['opening_balance', $clearing],
            'fixed_assets.cost' => ['fixed_assets', $faCost],
            'fixed_assets.equipment_accumulated_depreciation' => ['fixed_assets', $faAccumulated],
        ] as $key => [$module, $accountId]) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                ['module' => $module, 'account_id' => $accountId, 'is_required' => true, 'is_active' => true],
            );
        }

        return [
            'cash' => $cash, 'bank' => $bank, 'payable' => $payable,
            'equity' => $equity, 'clearing' => $clearing, 'fa_cost' => $faCost,
        ];
    }

    private function account(string $code, string $name, string $type, string $normalBalance, ?int $parentId = null): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'parent_account_id' => $parentId,
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
        CompanySetupState::query()->create([
            'company_id' => $company->id,
            'opening_date' => '2026-01-01',
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
