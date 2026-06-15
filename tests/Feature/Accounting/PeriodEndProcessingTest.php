<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\PeriodEndRun;
use App\Services\Accounting\FiscalYearService;
use Tests\Feature\Journal\JournalTestCase;

class PeriodEndProcessingTest extends JournalTestCase
{
    public function test_period_end_routes_require_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->getJson('/api/accounting/period-end/checklist?period=2026-03', $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_checklist_blocks_when_accounting_period_missing(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $response = $this->getJson('/api/accounting/period-end/checklist?period=2026-03', $ctx['headers'])
            ->assertOk();

        $response->assertJsonPath('data.status', 'blocked');
        $response->assertJsonPath('data.blocking_errors.0.code', 'ACCOUNTING_PERIOD_NOT_FOUND');
    }

    public function test_run_zero_line_fixed_asset_routine_is_idempotent_and_closes_period(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company'], 2026);
        $this->seedFixedAssetMappings();

        $first = $this->postJson('/api/accounting/period-end/run', [
            'period' => '2026-03',
        ], $ctx['headers'])->assertOk();

        $first->assertJsonPath('data.period', '2026-03');
        $first->assertJsonPath('data.status', 'completed');
        $first->assertJsonPath('data.routines.0.routine_key', 'fixed_asset_depreciation');
        $first->assertJsonPath('data.routines.0.status', 'skipped');

        $period = AccountingPeriod::query()
            ->where('company_id', $ctx['company']->id)
            ->where('period_year', 2026)
            ->where('period_month', 3)
            ->firstOrFail();
        $this->assertSame('closed', $period->status);

        $second = $this->postJson('/api/accounting/period-end/run', [
            'period' => '2026-03',
        ], $ctx['headers'])->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, PeriodEndRun::query()->where('period', '2026-03')->count());
    }

    private function seedFixedAssetMappings(): void
    {
        $asset = ChartOfAccount::query()->create([
            'account_code' => '1500',
            'account_name' => 'Fixed Asset',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $expense = ChartOfAccount::query()->create([
            'account_code' => '6150',
            'account_name' => 'Depreciation Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $revenue = ChartOfAccount::query()->create([
            'account_code' => '7200',
            'account_name' => 'Gain on Disposal',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        foreach ([
            'fixed_assets.clearing' => $asset->id,
            'fixed_assets.cost' => $asset->id,
            'fixed_assets.accumulated_depreciation' => $asset->id,
            'fixed_assets.depreciation_expense' => $expense->id,
            'fixed_assets.disposal_gain' => $revenue->id,
            'fixed_assets.disposal_loss' => $expense->id,
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
}
