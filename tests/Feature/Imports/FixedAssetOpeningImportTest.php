<?php

namespace Tests\Feature\Imports;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Settings\Services\CompanySettingService;
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
 * Profil impor aset tetap awal — Fase 7 rencana impor data.
 */
class FixedAssetOpeningImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_creates_draft_register_records_without_any_journal(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->enableFixedAssets($ctx);
        $this->seedAccountsAndCategories();

        $uuid = $this->uploadAndMap($ctx, [
            $this->headers(),
            ['Toyota Avanza', 'VEHICLE', '15/03/2023', '250000000', '75000000', '0', '8', '1', '15/03/2023', '', '', 'Operasional'],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 1);

        $asset = FixedAsset::query()->firstOrFail();
        $this->assertSame('opening_import', $asset->source_type);
        $this->assertSame('draft', $asset->status);

        // Regresi langsung dari celah StoreFixedAssetRequest: sebelum Fase 7,
        // `validated()` membuang accumulated_depreciation diam-diam sehingga
        // aset warisan masuk dengan nilai buku sebesar harga perolehan penuh.
        $this->assertEqualsWithDelta(75000000, (float) $asset->accumulated_depreciation, 0.001);
        $this->assertEqualsWithDelta(175000000, (float) $asset->net_book_value, 0.001);

        // Aturan tertulis: opening import membuat register saja, tanpa jurnal.
        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_unknown_category_is_rejected_at_preview(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->enableFixedAssets($ctx);
        $this->seedAccountsAndCategories();

        $uuid = $this->uploadAndMap($ctx, [
            $this->headers(),
            ['Mesin Tak Dikenal', 'TIDAK_ADA', '15/03/2023', '1000000', '0', '0', '4', '1', '', '', '', ''],
        ], expectedFailedRows: 1);

        $errors = $this->firstRowErrors($uuid);
        $this->assertStringContainsString('TIDAK_ADA', $errors['category'][0]);
    }

    public function test_category_can_be_matched_by_name_case_insensitively(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->enableFixedAssets($ctx);
        $this->seedAccountsAndCategories();

        $uuid = $this->uploadAndMap($ctx, [
            $this->headers(),
            ['Laptop', 'komputer dan perangkat it', '01/07/2024', '18000000', '0', '0', '4', '1', '', '', '', ''],
        ]);

        $this->postJson('/api/imports/'.$uuid.'/commit', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.committed_rows', 1);

        $this->assertSame('IT_EQUIP', FixedAsset::query()->firstOrFail()->category->code);
    }

    public function test_accumulated_depreciation_above_basis_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->enableFixedAssets($ctx);
        $this->seedAccountsAndCategories();

        $uuid = $this->uploadAndMap($ctx, [
            $this->headers(),
            // Akumulasi 12jt > (10jt cost - 1jt salvage) = 9jt.
            ['Laptop Bekas', 'IT_EQUIP', '01/01/2020', '10000000', '12000000', '1000000', '4', '1', '', '', '', ''],
        ], expectedFailedRows: 1);

        $errors = $this->firstRowErrors($uuid);
        $this->assertArrayHasKey('accumulated_depreciation', $errors);
    }

    public function test_invalid_useful_life_is_rejected(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        $this->enableFixedAssets($ctx);
        $this->seedAccountsAndCategories();

        $uuid = $this->uploadAndMap($ctx, [
            $this->headers(),
            ['Laptop', 'IT_EQUIP', '01/01/2024', '10000000', '0', '0', '7', '1', '', '', '', ''],
        ], expectedFailedRows: 1);

        $this->assertArrayHasKey('useful_life_years', $this->firstRowErrors($uuid));
    }

    public function test_import_is_blocked_when_fixed_asset_module_is_disabled(): void
    {
        Storage::fake('local');
        $ctx = $this->setUpTenant();
        // Modul sengaja TIDAK diaktifkan.
        $this->seedAccountsAndCategories();

        $uuid = $this->uploadAndMap($ctx, [
            $this->headers(),
            ['Laptop', 'IT_EQUIP', '01/01/2024', '10000000', '0', '0', '4', '1', '', '', '', ''],
        ], expectedFailedRows: 1);

        $this->assertStringContainsString('Modul Aktiva Tetap', $this->firstRowErrors($uuid)['name'][0]);
        $this->assertSame(0, FixedAsset::query()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function headers(): array
    {
        return ['Name', 'Category', 'Acquisition Date', 'Acquisition Cost', 'Accumulated Depreciation', 'Salvage Value', 'Useful Life Years', 'Quantity', 'Service Start Date', 'Department', 'Project', 'Description'];
    }

    private function mapping(): array
    {
        return [
            'name' => 'Name',
            'category' => 'Category',
            'acquisition_date' => 'Acquisition Date',
            'acquisition_cost' => 'Acquisition Cost',
            'accumulated_depreciation' => 'Accumulated Depreciation',
            'salvage_value' => 'Salvage Value',
            'useful_life_years' => 'Useful Life Years',
            'quantity' => 'Quantity',
            'service_start_date' => 'Service Start Date',
            'department' => 'Department',
            'project' => 'Project',
            'description' => 'Description',
        ];
    }

    private function uploadAndMap(array $ctx, array $rows, int $expectedFailedRows = 0): string
    {
        $batch = $this->postJson('/api/imports', [
            'profile' => 'fixed_asset_opening',
            'file' => $this->csvFile('fa.csv', $rows),
        ], $ctx['headers'])->assertCreated()->json('data.batch');

        $this->patchJson('/api/imports/'.$batch['uuid'].'/mapping', [
            'column_map' => $this->mapping(),
        ], $ctx['headers'])->assertOk()->assertJsonPath('data.failed_rows', $expectedFailedRows);

        return $batch['uuid'];
    }

    private function firstRowErrors(string $uuid): array
    {
        $batchId = ImportBatch::query()->where('uuid', $uuid)->firstOrFail()->id;

        return (array) ImportRow::query()->where('import_batch_id', $batchId)->firstOrFail()->errors;
    }

    private function enableFixedAssets(array $ctx): void
    {
        app(CompanySettingService::class)->updateModuleSetting($ctx['company'], ['fixed_asset_enabled' => true]);
    }

    /**
     * COA minimal + mapping aset tetap, lalu kategori default ditanam lewat
     * service yang sama dengan yang dipakai provisioning.
     */
    private function seedAccountsAndCategories(): void
    {
        $cost = $this->account('1530', 'Peralatan', 'asset', 'debit');
        $accumulated = $this->account('1531', 'Akumulasi Penyusutan Peralatan', 'asset', 'credit');
        $expense = $this->account('6172', 'Beban Penyusutan Peralatan', 'expense', 'debit');
        $revenue = $this->account('4900', 'Laba Pelepasan Aset', 'revenue', 'credit');

        foreach ([
            'fixed_assets.cost' => $cost,
            'fixed_assets.clearing' => $cost,
            'fixed_assets.accumulated_depreciation' => $accumulated,
            'fixed_assets.accumulated_amortization' => $accumulated,
            'fixed_assets.depreciation_expense' => $expense,
            'fixed_assets.amortization_expense' => $expense,
            'fixed_assets.disposal_gain' => $revenue,
            'fixed_assets.disposal_loss' => $expense,
            'fixed_assets.equipment_cost' => $cost,
            'fixed_assets.equipment_accumulated_depreciation' => $accumulated,
            'fixed_assets.equipment_depreciation_expense' => $expense,
        ] as $key => $accountId) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                ['module' => 'fixed_assets', 'account_id' => $accountId, 'is_required' => false, 'is_active' => true],
            );
        }

        // Kategori sendiri sudah ada dari migration tenant; yang dibutuhkan di
        // sini cuma mapping akunnya supaya FixedAssetService punya akun untuk
        // dijadikan fallback.
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
            'name' => 'FA Import '.$user->id, 'slug' => 'fa-import-'.$user->id,
            'code' => 'FAI-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $tenantPath = database_path('tenants/test_fai_'.$company->id.'_'.uniqid().'.sqlite');
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
        $path = tempnam(sys_get_temp_dir(), 'fai_csv_');
        $h = fopen($path, 'w');
        foreach ($rows as $r) {
            fputcsv($h, $r);
        }
        fclose($h);

        return new UploadedFile($path, $name, 'text/csv', null, true);
    }
}
