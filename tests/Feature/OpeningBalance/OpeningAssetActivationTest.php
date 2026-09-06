<?php

namespace Tests\Feature\OpeningBalance;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Services\FixedAssetService;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Services\OpeningBalanceService;
use App\Modules\Settings\Services\CompanySettingService;
use App\Modules\Setup\Services\CoaTemplateService;
use App\Shared\Models\CompanySetupState;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantContext;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Aktivasi aset tetap awal dan rekonsiliasinya — Fase 8.
 *
 * Aset saldo awal **tidak dibukukan** oleh register: nilainya masuk buku besar
 * lewat berkas saldo awal, seperti akun lain. Yang dikerjakan aktivasi cuma
 * kartu asetnya — nomor aset, tanggal kapitalisasi, dan jadwal penyusutan yang
 * menghitung SISA nilai selama SISA umur.
 *
 * Karena keduanya tidak lagi saling menurunkan, saldo akun dan register **boleh
 * berbeda**. Itu bukan galat: tanah bisa berdiri di beberapa lokasi sementara
 * yang terdaftar baru satu. Yang wajib ada cuma laporannya.
 */
class OpeningAssetActivationTest extends JournalTestCase
{
    private const COST = 250000000.0;

    private const ACCUMULATED = 23437500.0;

    public function test_activation_capitalises_the_asset_and_schedules_only_the_remaining_life(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $asset = $this->createOpeningVehicle();

        $this->assertSame('draft', $asset->status);

        $activated = app(FixedAssetService::class)->activateOpeningAssets('2026-01-01');
        $this->assertSame(1, $activated);

        $asset->refresh();
        $this->assertSame('active', $asset->status);
        $this->assertNotNull($asset->asset_number);
        $this->assertSame('2026-01-01', $asset->capitalized_at?->toDateString());

        // Umur 8 tahun (96 bulan), sudah terpakai 9 bulan sampai tanggal saldo
        // awal → sisa 87 bulan. Jadwalnya hanya boleh mencakup sisa itu.
        $this->assertSame(87, $asset->schedules()->count());
        $this->assertEqualsWithDelta(
            self::COST - self::ACCUMULATED,
            (float) $asset->schedules()->sum('depreciation_amount'),
            1.0,
        );
    }

    public function test_activation_creates_no_journal_at_all(): void
    {
        $this->setUpOpeningTenant();
        $this->createOpeningVehicle();

        app(FixedAssetService::class)->activateOpeningAssets('2026-01-01');

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_activation_is_idempotent(): void
    {
        $this->setUpOpeningTenant();
        $this->createOpeningVehicle();

        $service = app(FixedAssetService::class);
        $this->assertSame(1, $service->activateOpeningAssets('2026-01-01'));
        // Panggilan kedua tidak menyusun ulang jadwal aset yang sudah aktif.
        $this->assertSame(0, $service->activateOpeningAssets('2026-01-01'));
    }

    /**
     * Skenario yang diminta pemilik produk: akun Tanah 100jt di buku besar, tapi
     * yang baru didaftarkan satu lokasi senilai 60jt. Impor tetap sukses, dan
     * selisihnya muncul sebagai laporan — bukan penolakan.
     */
    public function test_partial_asset_registration_is_reported_not_rejected(): void
    {
        $ctx = $this->setUpOpeningTenant();

        $this->createOpeningLand(60000000);
        app(FixedAssetService::class)->activateOpeningAssets('2026-01-01');

        // Saldo akun tanah diisi lewat berkas saldo awal, untuk dua lokasi.
        app(OpeningBalanceService::class)->postOpeningJournal([[
            'account_id' => $this->accountId('1540'),
            'debit' => 100000000,
            'credit' => 0,
            'description' => 'Saldo awal tanah',
        ]]);

        $reconciliation = $this->getJson('/api/opening-balance/status', $ctx['headers'])
            ->assertOk()
            ->json('data.fixed_asset_reconciliation');

        $this->assertTrue($reconciliation['has_difference']);

        $landRow = collect($reconciliation['rows'])->firstWhere('account_code', '1540');
        $this->assertEqualsWithDelta(60000000, (float) $landRow['register_amount'], 0.001);
        $this->assertEqualsWithDelta(100000000, (float) $landRow['gl_amount'], 0.001);
        $this->assertEqualsWithDelta(40000000, (float) $landRow['difference'], 0.001);
    }

    public function test_reconciliation_reports_no_difference_once_registration_is_complete(): void
    {
        $ctx = $this->setUpOpeningTenant();

        $this->createOpeningLand(100000000);
        app(FixedAssetService::class)->activateOpeningAssets('2026-01-01');

        app(OpeningBalanceService::class)->postOpeningJournal([[
            'account_id' => $this->accountId('1540'),
            'debit' => 100000000,
            'credit' => 0,
            'description' => 'Saldo awal tanah',
        ]]);

        $this->getJson('/api/opening-balance/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.fixed_asset_reconciliation.has_difference', false);
    }

    public function test_reverting_an_import_deletes_its_asset_cards(): void
    {
        $this->setUpOpeningTenant();
        $asset = $this->createOpeningVehicle(importBatchUuid: 'batch-abc');
        app(FixedAssetService::class)->activateOpeningAssets('2026-01-01');

        $deleted = app(FixedAssetService::class)->deleteImportedOpeningAssets('batch-abc');

        $this->assertSame(1, $deleted);
        $this->assertNull(FixedAsset::query()->find($asset->id));
        $this->assertSame(0, DB::connection('tenant')->table('fixed_asset_depreciation_schedules')->count());
    }

    public function test_revert_is_refused_entirely_when_depreciation_is_already_posted(): void
    {
        $this->setUpOpeningTenant();
        $asset = $this->createOpeningVehicle(importBatchUuid: 'batch-abc');
        app(FixedAssetService::class)->activateOpeningAssets('2026-01-01');

        DB::connection('tenant')->table('fixed_asset_depreciation_schedules')
            ->where('fixed_asset_id', $asset->id)
            ->limit(1)
            ->update(['status' => 'posted']);

        $this->expectExceptionMessage('sudah punya penyusutan terposting');
        app(FixedAssetService::class)->deleteImportedOpeningAssets('batch-abc');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function setUpOpeningTenant(): array
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->updateModuleSetting($ctx['company'], ['fixed_asset_enabled' => true]);

        $service = app(CoaTemplateService::class);
        $template = collect($service->templates())->firstWhere('id', 'trading');
        $service->applyTemplate('trading', $template['accounts']);

        CompanySetupState::query()->updateOrCreate(
            ['company_id' => $ctx['company']->id],
            ['opening_date' => '2026-01-01'],
        );

        ChartOfAccount::query()->firstOrCreate(
            ['account_code' => '1540'],
            ['account_name' => 'Tanah', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true],
        );

        AccountMapping::query()->updateOrCreate(
            ['mapping_key' => 'opening_balance.clearing'],
            ['module' => 'opening_balance', 'account_id' => $this->accountId('3900'), 'is_required' => true, 'is_active' => true],
        );

        DB::connection('tenant')->table('fixed_asset_categories')
            ->where('code', 'LAND')
            ->update(['asset_account_id' => $this->accountId('1540')]);

        app(TenantContext::class)->set(
            $ctx['company'],
            CompanyUser::query()->where('company_id', $ctx['company']->id)->firstOrFail(),
            TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail(),
        );

        return $ctx;
    }

    private function createOpeningVehicle(?string $importBatchUuid = null): FixedAsset
    {
        return app(FixedAssetService::class)->create([
            'name' => 'Toyota Avanza B 1234 XYZ',
            'fixed_asset_category_id' => $this->categoryId('VEHICLE'),
            'acquisition_date' => '2025-03-10',
            'service_start_date' => '2025-03-10',
            'useful_life_years' => 8,
            'acquisition_cost' => self::COST,
            'accumulated_depreciation' => self::ACCUMULATED,
            'source_type' => 'opening_import',
            'metadata' => $importBatchUuid ? ['import_batch_uuid' => $importBatchUuid] : [],
        ]);
    }

    private function createOpeningLand(float $cost): FixedAsset
    {
        return app(FixedAssetService::class)->create([
            'name' => 'Tanah Lokasi A',
            'fixed_asset_category_id' => $this->categoryId('LAND'),
            'acquisition_date' => '2024-01-10',
            'service_start_date' => '2024-01-10',
            'acquisition_cost' => $cost,
            'accumulated_depreciation' => 0,
            'source_type' => 'opening_import',
        ]);
    }

    private function accountId(string $code): int
    {
        return (int) ChartOfAccount::query()->where('account_code', $code)->value('id');
    }

    private function categoryId(string $code): int
    {
        return (int) DB::connection('tenant')->table('fixed_asset_categories')->where('code', $code)->value('id');
    }
}
