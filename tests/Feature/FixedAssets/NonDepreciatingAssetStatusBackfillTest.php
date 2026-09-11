<?php

declare(strict_types=1);

namespace Tests\Feature\FixedAssets;

use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\FixedAssets\Models\FixedAssetDepreciationSchedule;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Aset yang memang tidak menyusut -- tanah, aset dalam penyelesaian, goodwill --
 * pernah ditandai `fully_depreciated` begitu diaktifkan lewat impor saldo awal,
 * karena status lifecycle-nya diputuskan dari "tidak punya jadwal tersisa"
 * belaka. Register jadi mengklaim tanah sudah habis nilainya padahal
 * akumulasinya nol.
 *
 * Yang dijaga test ini: migration mengembalikan status baris yang terlanjur
 * salah, dan TIDAK menyentuh aset yang statusnya memang benar.
 */
class NonDepreciatingAssetStatusBackfillTest extends JournalTestCase
{
    public function test_migration_reactivates_land_and_goodwill_wrongly_marked_fully_depreciated(): void
    {
        $this->setUpTenant(role: 'owner');

        $land = $this->asset('FA-LAND', 'none', ['status' => 'fully_depreciated']);
        $cip = $this->asset('FA-CIP', 'none', ['status' => 'fully_depreciated']);
        $goodwill = $this->asset('FA-GOODWILL', 'impairment_only', ['status' => 'fully_depreciated']);

        $this->runBackfill();

        foreach ([$land, $cip, $goodwill] as $asset) {
            $this->assertSame('active', $asset->refresh()->status);
        }
    }

    public function test_migration_restores_partially_disposed_instead_of_active(): void
    {
        $this->setUpTenant(role: 'owner');

        $land = $this->asset('FA-LAND-SPLIT', 'none', [
            'status' => 'fully_depreciated',
            'quantity' => 4,
            'remaining_quantity' => 3,
        ]);

        $this->runBackfill();

        $this->assertSame('partially_disposed', $land->refresh()->status);
    }

    public function test_migration_leaves_a_genuinely_depreciated_asset_alone(): void
    {
        $this->setUpTenant(role: 'owner');

        $vehicle = $this->asset('FA-VEHICLE', 'depreciation', [
            'status' => 'fully_depreciated',
            'accumulated_depreciation' => 100.00,
            'net_book_value' => 0,
        ]);

        $this->runBackfill();

        $this->assertSame('fully_depreciated', $vehicle->refresh()->status);
    }

    public function test_migration_leaves_an_asset_that_actually_depreciated_before_reclassification_alone(): void
    {
        $this->setUpTenant(role: 'owner');

        // Aset yang benar-benar habis disusutkan lalu kategorinya dipindah ke
        // non-depresiasi belakangan: akumulasinya tidak nol, jadi statusnya
        // memang `fully_depreciated` dan tidak boleh diutak-atik.
        $reclassified = $this->asset('FA-RECLASS', 'none', [
            'status' => 'fully_depreciated',
            'accumulated_depreciation' => 100.00,
            'net_book_value' => 0,
        ]);

        // Aset yang akumulasinya nol tapi punya jadwal `posted` -- penjaga kedua.
        $posted = $this->asset('FA-POSTED', 'none', ['status' => 'fully_depreciated']);
        FixedAssetDepreciationSchedule::query()->create([
            'fixed_asset_id' => $posted->id,
            'period_year' => 2026,
            'period_month' => 2,
            'period' => '2026-02',
            'depreciation_amount' => 0,
            'accumulated_depreciation_after' => 0,
            'net_book_value_after' => 0,
            'status' => 'posted',
        ]);

        $this->runBackfill();

        $this->assertSame('fully_depreciated', $reclassified->refresh()->status);
        $this->assertSame('fully_depreciated', $posted->refresh()->status);
    }

    public function test_migration_leaves_a_disposed_asset_alone(): void
    {
        $this->setUpTenant(role: 'owner');

        $disposed = $this->asset('FA-DISPOSED', 'none', [
            'status' => 'fully_depreciated',
            'disposed_at' => '2026-03-01 00:00:00',
        ]);

        $this->runBackfill();

        $this->assertSame('fully_depreciated', $disposed->refresh()->status);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function runBackfill(): void
    {
        $migration = require database_path('migrations/tenant/2026_09_10_000001_restore_status_of_non_depreciating_fixed_assets.php');
        $migration->up();
    }

    private function asset(string $number, string $depreciationType, array $overrides = []): FixedAsset
    {
        $category = FixedAssetCategory::query()->create([
            'code' => 'CAT-'.$number,
            'name' => 'Category '.$number,
            'asset_class' => 'tangible',
            'depreciation_type' => $depreciationType,
            'default_useful_life_years' => null,
            'is_active' => true,
        ]);

        return FixedAsset::query()->create(array_merge([
            'asset_number' => $number,
            'name' => 'Asset '.$number,
            'fixed_asset_category_id' => $category->id,
            'asset_class' => 'tangible',
            'depreciation_type' => $depreciationType,
            'depreciation_method' => $depreciationType === 'depreciation' ? 'straight_line' : 'none',
            'status' => 'active',
            'source_type' => 'opening_import',
            'acquisition_date' => '2022-01-10',
            'service_start_date' => '2022-01-10',
            'quantity' => 1,
            'remaining_quantity' => 1,
            'unit_acquisition_cost' => 100.00,
            'acquisition_cost' => 100.00,
            'salvage_value' => 0,
            'depreciable_basis' => 100.00,
            'accumulated_depreciation' => 0,
            'net_book_value' => 100.00,
        ], $overrides));
    }
}
