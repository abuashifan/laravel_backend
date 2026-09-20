<?php

declare(strict_types=1);

namespace Tests\Feature\FixedAssets;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Kunci mapping penyusutan generik (`fixed_assets.accumulated_depreciation` dan
 * `fixed_assets.depreciation_expense`) dihapus karena sudah dipecah per kelas
 * aset -- membiarkan keduanya berdampingan membuat halaman Pemetaan Akun
 * menampilkan dua field untuk akun yang sama.
 *
 * Yang dijaga test ini: field gandanya benar-benar hilang, dan tenant lama yang
 * masih menyimpan barisnya tidak kehilangan akun fallback saat baris itu
 * dibuang migration.
 */
class GenericDepreciationMappingRemovalTest extends JournalTestCase
{
    public function test_settings_page_no_longer_offers_a_second_depreciation_field(): void
    {
        $definitions = (array) config('account_mappings.required_mappings');

        $this->assertArrayNotHasKey('fixed_assets.accumulated_depreciation', $definitions);
        $this->assertArrayNotHasKey('fixed_assets.depreciation_expense', $definitions);

        // Penggantinya harus tetap ada -- kalau ikut hilang, penyusutan tidak
        // punya akun sama sekali.
        foreach (['vehicle', 'building', 'equipment'] as $class) {
            $this->assertArrayHasKey("fixed_assets.{$class}_accumulated_depreciation", $definitions);
            $this->assertArrayHasKey("fixed_assets.{$class}_depreciation_expense", $definitions);
        }

        $labels = collect($definitions)
            ->filter(fn (array $definition): bool => $definition['module'] === 'fixed_assets' && $definition['visible_in_settings'])
            ->pluck('label');

        $this->assertSame($labels->unique()->count(), $labels->count(), 'Label mapping aset tetap tidak boleh ada yang kembar.');
    }

    public function test_migration_hands_the_old_account_to_the_equipment_key_before_deleting_it(): void
    {
        $this->setUpTenant(role: 'owner');

        $accumulated = $this->account('1521', 'Akumulasi Penyusutan Aset Tetap', 'asset', 'credit');
        $expense = $this->account('6170', 'Beban Penyusutan', 'expense', 'debit');
        $this->seedLegacyMapping('fixed_assets.accumulated_depreciation', $accumulated);
        $this->seedLegacyMapping('fixed_assets.depreciation_expense', $expense);

        $this->runRemovalMigration();

        $this->assertNull(AccountMapping::query()->where('mapping_key', 'fixed_assets.accumulated_depreciation')->first());
        $this->assertNull(AccountMapping::query()->where('mapping_key', 'fixed_assets.depreciation_expense')->first());

        $this->assertSame($accumulated, $this->mappedAccount('fixed_assets.equipment_accumulated_depreciation'));
        $this->assertSame($expense, $this->mappedAccount('fixed_assets.equipment_depreciation_expense'));
    }

    public function test_migration_keeps_a_per_class_account_that_is_already_set(): void
    {
        $this->setUpTenant(role: 'owner');

        $legacy = $this->account('1521', 'Akumulasi Penyusutan Aset Tetap', 'asset', 'credit');
        $perClass = $this->account('1531', 'Akumulasi Penyusutan Peralatan', 'asset', 'credit');
        $this->seedLegacyMapping('fixed_assets.accumulated_depreciation', $legacy);
        $this->seedLegacyMapping('fixed_assets.equipment_accumulated_depreciation', $perClass);

        $this->runRemovalMigration();

        $this->assertSame($perClass, $this->mappedAccount('fixed_assets.equipment_accumulated_depreciation'));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function runRemovalMigration(): void
    {
        $migration = require database_path('migrations/tenant/2026_08_28_000001_drop_generic_fixed_asset_depreciation_mappings.php');
        $migration->up();
    }

    private function seedLegacyMapping(string $key, int $accountId): void
    {
        AccountMapping::query()->updateOrCreate(
            ['mapping_key' => $key],
            ['module' => 'fixed_assets', 'account_id' => $accountId, 'is_required' => false, 'is_active' => true],
        );
    }

    private function mappedAccount(string $key): ?int
    {
        $accountId = AccountMapping::query()->where('mapping_key', $key)->value('account_id');

        return $accountId === null ? null : (int) $accountId;
    }

    private function account(string $code, string $name, string $type, string $normalBalance): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ])->id;
    }
}
