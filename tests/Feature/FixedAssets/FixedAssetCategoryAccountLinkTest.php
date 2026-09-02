<?php

namespace Tests\Feature\FixedAssets;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\FixedAssets\Services\FixedAssetCategoryAccountLinker;
use App\Modules\FixedAssets\Services\FixedAssetService;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Setup\Services\CoaTemplateService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fase 7A — penyambungan akun untuk kategori aset tetap.
 *
 * Kategorinya sendiri sudah ditanam migration tenant
 * `2026_06_15_000001_create_fixed_asset_tables.php` sejak tabelnya dibuat.
 * Yang tidak pernah terjadi adalah pengisian kolom `*_account_id`-nya —
 * migration itu jalan sebelum satu akun COA pun ada. Akibatnya 12 kunci
 * mapping per kelas dari commit 3e6bcbb tidak pernah menggerakkan jurnal.
 */
class FixedAssetCategoryAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_provides_default_categories_but_leaves_accounts_empty(): void
    {
        $this->setUpTenant();

        $categories = FixedAssetCategory::query()->get();

        $this->assertCount(15, $categories);
        foreach (['LAND', 'BUILDING', 'VEHICLE', 'IT_EQUIP', 'SOFTWARE', 'GOODWILL', 'OTHER'] as $code) {
            $this->assertTrue($categories->contains('code', $code), "Kategori {$code} harus ada dari migration.");
        }

        // Justru inilah celah yang ditutup Fase 7A.
        $this->assertSame(0, $categories->whereNotNull('asset_account_id')->count());
    }

    public function test_applying_coa_template_wires_categories_to_per_class_accounts(): void
    {
        $this->setUpTenant();
        $this->applyTradingTemplate();

        $accountIdByCode = ChartOfAccount::query()->pluck('id', 'account_code');

        foreach ([
            'VEHICLE' => ['1510', '1511', '6170'],
            'BUILDING' => ['1520', '1521', '6171'],
            'IT_EQUIP' => ['1530', '1531', '6172'],
            'SOFTWARE' => ['1540', '1541', '6175'],
        ] as $code => [$asset, $accumulated, $expense]) {
            $category = FixedAssetCategory::query()->where('code', $code)->firstOrFail();

            $this->assertSame((int) $accountIdByCode[$asset], (int) $category->asset_account_id, "asset account {$code}");
            $this->assertSame((int) $accountIdByCode[$accumulated], (int) $category->accumulated_depreciation_account_id, "accumulated account {$code}");
            $this->assertSame((int) $accountIdByCode[$expense], (int) $category->depreciation_expense_account_id, "expense account {$code}");
        }
    }

    /**
     * Dulu test ini bernama `..._stay_unlinked_on_purpose` dan menegaskan
     * kebalikannya: ketiga kategori ini SENGAJA dibiarkan null supaya jatuh ke
     * fallback `fixed_assets.cost`, "bukan diklaim ke akun Peralatan yang akan
     * salah di neraca".
     *
     * Alasannya benar, obatnya yang keliru — fallback itu MENUNJUK akun
     * Peralatan, jadi null menghasilkan tepat hasil yang hendak dihindari, cuma
     * lewat pintu lain dan tanpa satu pun galat. Sekarang ketiganya punya akun
     * sendiri di templat COA, jadi ia disambungkan seperti kelas lain.
     */
    public function test_non_depreciating_categories_are_wired_to_their_own_cost_accounts(): void
    {
        $this->setUpTenant();
        $this->applyTradingTemplate();

        $accountIdByCode = ChartOfAccount::query()->pluck('id', 'account_code');

        foreach ([
            'LAND' => '1500',
            'CIP' => '1550',
            'GOODWILL' => '1560',
        ] as $code => $assetCode) {
            $category = FixedAssetCategory::query()->where('code', $code)->firstOrFail();

            $this->assertSame((int) $accountIdByCode[$assetCode], (int) $category->asset_account_id, "asset account {$code}");

            // Tidak disusutkan, jadi tidak punya akun akumulasi maupun beban.
            // Mengisinya justru akan menghidupkan kembali jalur salah kelas:
            // akumulasi tanah mendarat di Akumulasi Penyusutan Peralatan.
            $this->assertNull($category->accumulated_depreciation_account_id, "accumulated account {$code}");
            $this->assertNull($category->depreciation_expense_account_id, "expense account {$code}");
        }

        // Akun Peralatan tetap milik kelas Peralatan saja.
        $this->assertNotSame(
            (int) $accountIdByCode['1530'],
            (int) FixedAssetCategory::query()->where('code', 'LAND')->value('asset_account_id'),
        );
    }

    /**
     * Penjagaan intinya, dan alasan ia diletakkan di lapis resolusi akun.
     *
     * Tenant warisan yang COA-nya dibuat sebelum akun 1500/1550/1560 ada akan
     * tetap punya kategori LAND tanpa akun. Sebelum penjagaan ini, asetnya
     * diam-diam dibukukan ke akun Peralatan lewat fallback `fixed_assets.cost`
     * — tidak ada galat di pratinjau impor, di validasi saldo awal, maupun saat
     * posting. Sekarang ia gagal keras, dengan pesan yang menyebut apa yang
     * harus disetel.
     */
    public function test_non_depreciating_category_without_account_refuses_the_equipment_fallback(): void
    {
        $this->setUpTenant();
        $this->applyTradingTemplate();

        // Simulasikan tenant warisan: akun Tanah dicabut, kategori kembali null.
        FixedAssetCategory::query()->where('code', 'LAND')->update(['asset_account_id' => null]);

        $land = FixedAssetCategory::query()->where('code', 'LAND')->firstOrFail();
        FixedAsset::query()->create([
            'name' => 'Tanah Gudang',
            'fixed_asset_category_id' => $land->id,
            'asset_class' => $land->asset_class,
            'depreciation_type' => $land->depreciation_type,
            'status' => 'draft',
            'source_type' => 'opening_import',
            'acquisition_date' => '2019-01-10',
            'acquisition_cost' => 450000000,
            'quantity' => 1,
            'accumulated_depreciation' => 0,
        ]);

        try {
            app(FixedAssetService::class)->openingAssetTotals(null, '2026-01-01');
            $this->fail('Kategori non-penyusutan tanpa akun seharusnya ditolak, bukan jatuh ke akun Peralatan.');
        } catch (ApiException $e) {
            $this->assertSame('FIXED_ASSET_CATEGORY_ACCOUNT_REQUIRED', $e->codeName);
            $this->assertStringContainsString('Akun Aktiva', $e->getMessage());
        }
    }

    /**
     * Sisi lain penjagaan yang sama: kategori yang MENYUSUT tetap boleh memakai
     * fallback. Tenant yang baru dimigrasi belum memetakan satu pun kolom akun
     * kategori, jadi menolak semuanya akan memblokir perusahaan baru — bukan
     * cuma kasus yang benar-benar salah kelas.
     */
    public function test_depreciating_category_without_account_still_uses_the_fallback(): void
    {
        $this->setUpTenant();
        $this->applyTradingTemplate();

        FixedAssetCategory::query()->where('code', 'IT_EQUIP')->update(['asset_account_id' => null]);

        $category = FixedAssetCategory::query()->where('code', 'IT_EQUIP')->firstOrFail();
        FixedAsset::query()->create([
            'name' => 'Laptop Kantor',
            'fixed_asset_category_id' => $category->id,
            'asset_class' => $category->asset_class,
            'depreciation_type' => $category->depreciation_type,
            'status' => 'draft',
            'source_type' => 'opening_import',
            'acquisition_date' => '2024-07-01',
            'acquisition_cost' => 18000000,
            'quantity' => 1,
            'accumulated_depreciation' => 0,
        ]);

        $totals = app(FixedAssetService::class)->openingAssetTotals(null, '2026-01-01');

        $equipment = (int) ChartOfAccount::query()->where('account_code', '1530')->value('id');
        $this->assertSame([$equipment => 18000000.0], $totals['cost_by_account']);
    }

    public function test_linker_is_idempotent_and_never_overwrites_user_choices(): void
    {
        $this->setUpTenant();
        $this->applyTradingTemplate();

        $custom = $this->account('1599', 'Akun Pilihan User', 'asset', 'debit');
        $vehicle = FixedAssetCategory::query()->where('code', 'VEHICLE')->firstOrFail();
        $vehicle->forceFill(['asset_account_id' => $custom])->save();

        $count = FixedAssetCategory::query()->count();
        app(FixedAssetCategoryAccountLinker::class)->linkDefaults();

        $this->assertSame($count, FixedAssetCategory::query()->count());
        $this->assertSame($custom, (int) $vehicle->refresh()->asset_account_id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function applyTradingTemplate(): void
    {
        $service = app(CoaTemplateService::class);
        $template = collect($service->templates())->firstWhere('id', 'trading');
        $this->assertNotNull($template, 'Template COA "trading" harus ada.');

        $service->applyTemplate('trading', $template['accounts']);
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
            'name' => 'FA Cat '.$user->id, 'slug' => 'fa-cat-'.$user->id,
            'code' => 'FAC-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT),
            'status' => 'active', 'created_by' => $user->id,
        ]);
        CompanyUser::query()->create([
            'company_id' => $company->id, 'user_id' => $user->id,
            'role' => $role, 'status' => 'active', 'joined_at' => now(),
        ]);
        $tenantPath = database_path('tenants/test_fac_'.$company->id.'_'.uniqid().'.sqlite');
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
}
