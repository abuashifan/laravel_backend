<?php

declare(strict_types=1);

namespace Tests\Feature\FixedAssets;

use App\Models\CompanyUser;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\FixedAsset;
use App\Models\Tenant\FixedAssetCategory;
use App\Models\Tenant\FixedAssetDepreciationSchedule;
use App\Models\Tenant\FixedAssetTransaction;
use App\Models\TenantDatabase;
use App\Services\FixedAssets\FixedAssetService;
use App\Services\Tenant\TenantContext;
use Tests\Feature\Journal\JournalTestCase;

class FixedAssetServiceLifecycleTest extends JournalTestCase
{
    public function test_final_depreciation_marks_asset_fully_depreciated(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->seedFixedAssetMappings();
        $this->setTenantContext($ctx);

        $asset = $this->createAsset('FA-TEST-001', 100.00, 100.00, '2026-01-01', '2026-02-01');
        $this->createSchedule($asset, '2026-02', 100.00);

        app(FixedAssetService::class)->postDepreciationPeriod(2026, 2);

        $asset->refresh();

        $this->assertSame('fully_depreciated', $asset->status);
        $this->assertSame(100.00, (float) $asset->accumulated_depreciation);
        $this->assertSame(0.00, (float) $asset->net_book_value);
        $this->assertSame('posted', FixedAssetDepreciationSchedule::query()->where('fixed_asset_id', $asset->id)->firstOrFail()->status);
    }

    public function test_disposal_removes_future_depreciation_and_blocks_later_posting(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->seedFixedAssetMappings();
        $this->setTenantContext($ctx);

        $asset = $this->createAsset('FA-TEST-002', 200.00, 200.00, '2026-01-01', '2026-02-01');
        $this->createSchedule($asset, '2026-02', 100.00);
        $this->createSchedule($asset, '2026-03', 100.00);

        app(FixedAssetService::class)->dispose($asset, [
            'disposal_date' => '2026-02-10',
            'disposal_type' => 'write_off',
            'disposed_quantity' => 1,
            'proceeds_amount' => 0,
            'cash_bank_account_id' => null,
            'receivable_account_id' => null,
            'metadata' => null,
        ]);

        app(FixedAssetService::class)->postDepreciationPeriod(2026, 2);
        app(FixedAssetService::class)->postDepreciationPeriod(2026, 3);

        $asset->refresh();

        $this->assertSame('disposed', $asset->status);
        $this->assertSame(0, FixedAssetDepreciationSchedule::query()->where('fixed_asset_id', $asset->id)->where('status', 'posted')->count());
        $this->assertSame(1, FixedAssetTransaction::query()->where('fixed_asset_id', $asset->id)->where('transaction_type', 'disposal')->count());
        $this->assertSame(0, FixedAssetTransaction::query()->where('fixed_asset_id', $asset->id)->whereIn('transaction_type', ['depreciation', 'amortization'])->count());
    }

    private function setTenantContext(array $ctx): void
    {
        $tenantDatabase = TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail();
        $companyUser = CompanyUser::query()->where('company_id', $ctx['company']->id)->where('user_id', $ctx['user']->id)->firstOrFail();

        app(TenantContext::class)->set($ctx['company'], $companyUser, $tenantDatabase);
    }

    private function seedFixedAssetMappings(): void
    {
        $assetAccount = ChartOfAccount::query()->create([
            'account_code' => '1500',
            'account_name' => 'Fixed Asset',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $expenseAccount = ChartOfAccount::query()->create([
            'account_code' => '6150',
            'account_name' => 'Depreciation Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $revenueAccount = ChartOfAccount::query()->create([
            'account_code' => '7200',
            'account_name' => 'Gain on Disposal',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        foreach ([
            'fixed_assets.clearing' => $assetAccount->id,
            'fixed_assets.cost' => $assetAccount->id,
            'fixed_assets.accumulated_depreciation' => $assetAccount->id,
            'fixed_assets.depreciation_expense' => $expenseAccount->id,
            'fixed_assets.disposal_gain' => $revenueAccount->id,
            'fixed_assets.disposal_loss' => $expenseAccount->id,
        ] as $key => $accountId) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                [
                    'module' => 'fixed_assets',
                    'account_id' => $accountId,
                    'is_required' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    private function createAsset(string $number, float $cost, float $basis, string $acquisitionDate, string $serviceStartDate): FixedAsset
    {
        $category = FixedAssetCategory::query()->create([
            'code' => 'TEST-'.$number,
            'name' => 'Test Category '.$number,
            'asset_class' => 'tangible',
            'depreciation_type' => 'depreciation',
            'default_useful_life_years' => 4,
            'is_active' => true,
        ]);

        return FixedAsset::query()->create([
            'asset_number' => $number,
            'name' => 'Test Asset '.$number,
            'description' => null,
            'fixed_asset_category_id' => $category->id,
            'asset_class' => 'tangible',
            'depreciation_type' => 'depreciation',
            'depreciation_method' => 'straight_line',
            'status' => 'active',
            'acquisition_date' => $acquisitionDate,
            'service_start_date' => $serviceStartDate,
            'first_depreciation_period' => '2026-02',
            'last_depreciation_period' => '2026-02',
            'useful_life_years' => 1,
            'useful_life_months' => 1,
            'quantity' => 1,
            'remaining_quantity' => 1,
            'unit_acquisition_cost' => $cost,
            'acquisition_cost' => $cost,
            'salvage_value' => 0,
            'depreciable_basis' => $basis,
            'accumulated_depreciation' => 0,
            'net_book_value' => $cost,
        ]);
    }

    private function createSchedule(FixedAsset $asset, string $period, float $amount): void
    {
        FixedAssetDepreciationSchedule::query()->create([
            'fixed_asset_id' => $asset->id,
            'period_year' => (int) substr($period, 0, 4),
            'period_month' => (int) substr($period, 5, 2),
            'period' => $period,
            'depreciation_amount' => $amount,
            'accumulated_depreciation_after' => $amount,
            'net_book_value_after' => max(0, (float) $asset->acquisition_cost - $amount),
            'status' => 'scheduled',
        ]);
    }
}
