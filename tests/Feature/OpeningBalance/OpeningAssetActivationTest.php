<?php

namespace Tests\Feature\OpeningBalance;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Services\FixedAssetService;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Modules\OpeningBalance\Support\OpeningBalanceType;
use App\Modules\Settings\Services\CompanySettingService;
use App\Modules\Setup\Services\CoaTemplateService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Fase 7F — aktivasi aset tetap awal.
 *
 * Aset saldo awal dibukukan jurnal pembuka, bukan jurnal kapitalisasi. Karena
 * itu ia diaktifkan sebagai EFEK dari posting batch — bukan lewat langkah
 * terpisah yang bisa terlupa — dan jadwal penyusutannya menghitung SISA nilai
 * selama SISA umur.
 */
class OpeningAssetActivationTest extends JournalTestCase
{
    private const COST = 250000000.0;

    private const ACCUMULATED = 23437500.0;

    public function test_posting_activates_opening_assets_and_schedules_the_remaining_life(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $asset = $this->createOpeningVehicle();

        $this->assertSame('draft', $asset->status);
        $this->assertNull($asset->capitalized_at);
        $this->assertSame(0, $asset->schedules()->count());

        $batch = $this->postOpeningBatch($ctx, '2026-01-01');

        $asset->refresh();
        $this->assertSame('active', $asset->status);
        $this->assertSame('2026-01-01', $asset->capitalized_at?->toDateString());
        $this->assertSame((int) $batch['id'], (int) $asset->opening_balance_batch_id);
        $this->assertNotNull($asset->asset_number);

        // Jadwal: Januari 2026 (bulan tanggal saldo awal, BUKAN +1) sampai
        // Maret 2033 (akhir masa manfaat asli, tidak bergeser).
        $schedules = $asset->schedules()->orderBy('period')->get();
        $this->assertCount(87, $schedules);
        $this->assertSame('2026-01', $schedules->first()->period);
        $this->assertSame('2033-03', $schedules->last()->period);

        // Yang dijadwalkan hanya SISA nilai, dan berakhir tepat di basis penuh.
        $this->assertEqualsWithDelta(self::COST - self::ACCUMULATED, (float) $schedules->sum('depreciation_amount'), 0.05);
        $this->assertEqualsWithDelta(self::COST, (float) $schedules->last()->accumulated_depreciation_after, 0.05);
    }

    public function test_activation_creates_no_journal_beyond_the_opening_one(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $this->createOpeningVehicle();
        $this->postOpeningBatch($ctx, '2026-01-01');

        // Satu-satunya jurnal yang boleh ada adalah jurnal pembuka. Kapitalisasi
        // normal akan menambah Dr Aset / Cr Kliring — itu yang membukukan dobel.
        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame('opening_balance', JournalEntry::query()->firstOrFail()->source_type);
    }

    public function test_opening_control_lines_use_per_class_accounts(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $this->createOpeningVehicle();
        $this->postOpeningBatch($ctx, '2026-01-01');

        $journal = JournalEntry::query()->with('lines')->firstOrFail();
        $vehicleCost = $this->accountId('1510');
        $vehicleAccumulated = $this->accountId('1511');

        // Kalau baris pembuka memakai akun generik (1530/1531) sementara
        // penyusutan bulanan memakai akun per kelas, akumulasi satu aset
        // terbelah di dua akun.
        $this->assertEqualsWithDelta(self::COST, (float) $journal->lines->firstWhere('account_id', $vehicleCost)?->debit, 0.05);
        $this->assertEqualsWithDelta(self::ACCUMULATED, (float) $journal->lines->firstWhere('account_id', $vehicleAccumulated)?->credit, 0.05);
        $this->assertNull($journal->lines->firstWhere('account_id', $this->accountId('1530')));
    }

    public function test_blank_accumulated_depreciation_is_calculated_at_posting(): void
    {
        $ctx = $this->setUpOpeningTenant();

        // Persis aset yang sama dengan `createOpeningVehicle()`, TANPA angka
        // akumulasi. Aset warisan yang kolomnya dikosongkan berarti "hitungkan",
        // bukan "belum pernah disusutkan" -- dan hitungannya harus jatuh tepat
        // di angka yang dipakai test lain di kelas ini.
        $asset = app(FixedAssetService::class)->create([
            'name' => 'Toyota Avanza B 1234 XYZ',
            'fixed_asset_category_id' => $this->categoryId('VEHICLE'),
            'acquisition_date' => '2025-03-10',
            'service_start_date' => '2025-03-10',
            'useful_life_years' => 8,
            'acquisition_cost' => self::COST,
            'source_type' => 'opening_import',
        ]);

        // Selama masih draft angkanya belum ada: tanggal saldo awal belum pasti.
        $this->assertEqualsWithDelta(0, (float) $asset->accumulated_depreciation, 0.001);
        $this->assertTrue((bool) ($asset->metadata['accumulated_depreciation_auto'] ?? false));

        $this->postOpeningBatch($ctx, '2026-01-01');

        // 250jt / 96 bulan x 9 bulan (Apr 2025 s/d Des 2025).
        $asset->refresh();
        $this->assertEqualsWithDelta(self::ACCUMULATED, (float) $asset->accumulated_depreciation, 0.05);
        $this->assertEqualsWithDelta(self::COST - self::ACCUMULATED, (float) $asset->net_book_value, 0.05);

        // Jadwalnya harus sama persis dengan aset yang akumulasinya diketik user.
        $schedules = $asset->schedules()->orderBy('period')->get();
        $this->assertCount(87, $schedules);
        $this->assertSame('2026-01', $schedules->first()->period);
        $this->assertEqualsWithDelta(self::COST, (float) $schedules->last()->accumulated_depreciation_after, 0.05);
    }

    public function test_auto_accumulated_depreciation_reaches_the_general_ledger(): void
    {
        $ctx = $this->setUpOpeningTenant();
        app(FixedAssetService::class)->create([
            'name' => 'Toyota Avanza B 1234 XYZ',
            'fixed_asset_category_id' => $this->categoryId('VEHICLE'),
            'acquisition_date' => '2025-03-10',
            'service_start_date' => '2025-03-10',
            'useful_life_years' => 8,
            'acquisition_cost' => self::COST,
            'source_type' => 'opening_import',
        ]);

        $this->postOpeningBatch($ctx, '2026-01-01');

        // Baris sistem batch saldo awal dibentuk dari angka aset. Kalau
        // hitungan otomatis berjalan setelah baris itu dibentuk, kreditnya
        // akan nol dan neraca pembuka melebihkan nilai aset sebesar akumulasi.
        $journal = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        $accumulatedAccount = $this->accountId('1511');
        $this->assertEqualsWithDelta(
            self::ACCUMULATED,
            (float) $journal->lines->firstWhere('account_id', $accumulatedAccount)?->credit,
            0.05,
        );
    }

    public function test_correction_batch_only_books_the_newly_added_asset(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $this->createOpeningVehicle();
        $this->postOpeningBatch($ctx, '2026-01-01');

        // Klien melaporkan satu aset yang terlewat setelah setup selesai.
        $laptop = app(FixedAssetService::class)->create([
            'name' => 'Laptop Terlewat',
            'fixed_asset_category_id' => $this->categoryId('IT_EQUIP'),
            'acquisition_date' => '2025-06-01',
            'service_start_date' => '2025-06-01',
            'useful_life_years' => 4,
            'acquisition_cost' => 20000000,
            'accumulated_depreciation' => 4166666.67,
            'source_type' => 'opening_import',
        ]);

        $correction = $this->postOpeningBatch($ctx, '2026-04-01', OpeningBalanceType::CORRECTION);

        $laptop->refresh();
        $this->assertSame((int) $correction['id'], (int) $laptop->opening_balance_batch_id);
        $this->assertSame('2026-04-01', $laptop->capitalized_at?->toDateString());

        // Jurnal koreksi hanya memuat laptopnya — mobil dari batch pertama tidak
        // boleh dibukukan ulang.
        $correctionJournal = JournalEntry::query()->with('lines')->latest('id')->firstOrFail();
        $this->assertNull($correctionJournal->lines->firstWhere('account_id', $this->accountId('1510')));
        $this->assertEqualsWithDelta(20000000, (float) $correctionJournal->lines->firstWhere('account_id', $this->accountId('1530'))?->debit, 0.05);
    }

    public function test_capitalize_is_rejected_for_opening_assets(): void
    {
        $this->setUpOpeningTenant();
        $asset = $this->createOpeningVehicle();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Aset saldo awal tidak dikapitalisasi manual');

        app(FixedAssetService::class)->capitalize($asset, []);
    }

    public function test_reopening_returns_opening_assets_to_draft(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $asset = $this->createOpeningVehicle();
        $batch = $this->postOpeningBatch($ctx, '2026-01-01');

        $this->postJson('/api/opening-balance/batches/'.$batch['id'].'/reopen', [
            'reason' => 'Koreksi angka saldo awal sebelum perusahaan bertransaksi.',
        ], $ctx['headers'])->assertOk();

        $asset->refresh();
        $this->assertSame('draft', $asset->status);
        $this->assertNull($asset->capitalized_at);
        $this->assertNull($asset->opening_balance_batch_id);
        $this->assertSame(0, $asset->schedules()->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function setUpOpeningTenant(): array
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->updateModuleSetting($ctx['company'], ['fixed_asset_enabled' => true]);

        $service = app(CoaTemplateService::class);
        $template = collect($service->templates())->firstWhere('id', 'trading');
        $service->applyTemplate('trading', $template['accounts']);

        return $ctx;
    }

    private function createOpeningVehicle(): FixedAsset
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
        ]);
    }

    /**
     * Buat batch, isi baris ekuitas penyeimbang sebesar selisih, lalu posting.
     */
    private function postOpeningBatch(array $ctx, string $openingDate, string $type = OpeningBalanceType::STANDARD): array
    {
        $batch = $this->postJson('/api/opening-balance/batches', [
            'opening_date' => $openingDate,
            'type' => $type,
        ], $ctx['headers'])->assertCreated()->json('data');

        $preview = $this->getJson('/api/opening-balance/batches/'.$batch['id'].'/preview', $ctx['headers'])
            ->assertOk()->json('data');

        $difference = round((float) $preview['total_debit'] - (float) $preview['total_credit'], 2);
        $this->putJson('/api/opening-balance/batches/'.$batch['id'].'/lines', [
            'lines' => [[
                'account_id' => $this->accountId('3200'),
                'debit' => $difference < 0 ? abs($difference) : 0,
                'credit' => $difference > 0 ? $difference : 0,
                'description' => 'Penyeimbang saldo awal',
            ]],
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/opening-balance/batches/'.$batch['id'].'/validate', [], $ctx['headers'])->assertOk();
        $this->postJson('/api/opening-balance/batches/'.$batch['id'].'/post', [], $ctx['headers'])->assertOk();

        $this->assertTrue(OpeningBalanceBatch::query()->findOrFail($batch['id'])->postedOrLocked());

        return $batch;
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
