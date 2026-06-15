<?php

declare(strict_types=1);

namespace Tests\Feature\OpeningBalance;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\OpeningBalanceBatch;
use App\Services\Settings\CompanySettingService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

class OpeningBalanceTest extends JournalTestCase
{
    public function test_opening_balance_routes_require_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->getJson('/api/opening-balance/status', $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_batch_line_validate_preview_post_and_lock_flow(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $accounts = $this->seedOpeningAccounts();

        $batch = $this->postJson('/api/opening-balance/batches', [
            'opening_date' => '2026-01-01',
            'description' => 'Opening balance 2026',
        ], $ctx['headers'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        $this->putJson('/api/opening-balance/batches/'.$batch['id'].'/lines', [
            'lines' => [
                ['account_id' => $accounts['asset'], 'debit' => 1000, 'credit' => 0, 'description' => 'Cash opening'],
                ['account_id' => $accounts['equity'], 'debit' => 0, 'credit' => 1000, 'description' => 'Owner equity'],
            ],
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.total_debit', '1000.00')
            ->assertJsonPath('data.total_credit', '1000.00');

        $this->postJson('/api/opening-balance/batches/'.$batch['id'].'/validate', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $this->getJson('/api/opening-balance/batches/'.$batch['id'].'/preview', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.validation.valid', true)
            ->assertJsonPath('data.journal_payload.source_type', 'opening_balance');

        $posted = $this->postJson('/api/opening-balance/batches/'.$batch['id'].'/post', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'posted')
            ->json('data');

        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance')->where('source_id', $batch['id'])->count());
        $this->postJson('/api/opening-balance/batches/'.$batch['id'].'/post', [], $ctx['headers'])->assertOk();
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance')->where('source_id', $batch['id'])->count());

        $this->postJson('/api/opening-balance/batches/'.$batch['id'].'/lock', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'locked');

        $this->putJson('/api/opening-balance/batches/'.$batch['id'].'/lines', [
            'lines' => [
                ['account_id' => $accounts['asset'], 'debit' => 1000],
                ['account_id' => $accounts['equity'], 'credit' => 1000],
            ],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_BALANCE_NOT_EDITABLE');

        $this->assertNotNull($posted['journal_entry_id']);
    }

    public function test_setup_finalize_posts_and_locks_opening_balance(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $accounts = $this->seedOpeningAccounts();

        $batch = $this->postJson('/api/opening-balance/batches', [
            'opening_date' => '2026-01-01',
            'description' => 'Setup opening',
        ], $ctx['headers'])->assertCreated()->json('data');

        $this->putJson('/api/opening-balance/batches/'.$batch['id'].'/lines', [
            'lines' => [
                ['account_id' => $accounts['asset'], 'debit' => 500],
                ['account_id' => $accounts['equity'], 'credit' => 500],
            ],
        ], $ctx['headers'])->assertOk();

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'final_review',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertOk();

        foreach (['company_profile', 'module_selection', 'accounting_settings', 'chart_of_accounts', 'account_mappings', 'opening_balance_preview'] as $step) {
            $this->postJson('/api/setup/validate-step', [
                'step' => $step,
            ], $ctx['headers'])->assertOk();
        }

        $this->postJson('/api/setup/validate-all', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $this->postJson('/api/setup/finalize', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.finalized', true)
            ->assertJsonPath('data.state.status', 'finalized');

        $this->assertSame('locked', OpeningBalanceBatch::query()->findOrFail($batch['id'])->status);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'opening_balance')->where('source_id', $batch['id'])->count());
    }

    public function test_opening_balance_uses_fixed_asset_opening_import_as_system_lines(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->updateModuleSetting($ctx['company'], [
            'fixed_asset_enabled' => true,
        ]);
        $accounts = $this->seedOpeningAccounts();
        $categoryId = (int) DB::connection('tenant')->table('fixed_asset_categories')->where('code', 'IT_EQUIP')->value('id');

        DB::connection('tenant')->table('fixed_assets')->insert([
            'asset_number' => 'FA-OPEN-001',
            'name' => 'Opening Laptop',
            'fixed_asset_category_id' => $categoryId,
            'asset_class' => 'tangible',
            'depreciation_type' => 'depreciation',
            'depreciation_method' => 'straight_line',
            'status' => 'active',
            'acquisition_date' => '2025-01-01',
            'service_start_date' => '2025-01-01',
            'quantity' => 1,
            'remaining_quantity' => 1,
            'unit_acquisition_cost' => 1000,
            'acquisition_cost' => 1000,
            'salvage_value' => 0,
            'depreciable_basis' => 1000,
            'accumulated_depreciation' => 200,
            'net_book_value' => 800,
            'source_type' => 'opening_import',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batch = $this->postJson('/api/opening-balance/batches', [
            'opening_date' => '2026-01-01',
            'description' => 'Opening with fixed asset import',
        ], $ctx['headers'])->assertCreated()->json('data');

        $this->putJson('/api/opening-balance/batches/'.$batch['id'].'/lines', [
            'lines' => [
                ['account_id' => $accounts['fixed_asset_cost'], 'debit' => 1000],
                ['account_id' => $accounts['equity'], 'credit' => 1800],
            ],
        ], $ctx['headers'])->assertOk();

        $this->getJson('/api/opening-balance/batches/'.$batch['id'].'/preview', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.validation.valid', false)
            ->assertJsonPath('data.blocking_errors.0.code', 'FIXED_ASSET_CONTROL_DUPLICATE');

        $this->putJson('/api/opening-balance/batches/'.$batch['id'].'/lines', [
            'lines' => [
                ['account_id' => $accounts['equity'], 'credit' => 800],
            ],
        ], $ctx['headers'])->assertOk();

        $this->getJson('/api/opening-balance/batches/'.$batch['id'].'/preview', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.validation.valid', true)
            ->assertJsonPath('data.fixed_asset_totals.cost', 1000)
            ->assertJsonPath('data.fixed_asset_totals.accumulated_depreciation', 200)
            ->assertJsonPath('data.fixed_asset_totals.net_book_value', 800)
            ->assertJsonCount(2, 'data.system_lines');

        $this->postJson('/api/opening-balance/batches/'.$batch['id'].'/post', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $journal = JournalEntry::query()
            ->with('lines')
            ->where('source_type', 'opening_balance')
            ->where('source_id', $batch['id'])
            ->firstOrFail();

        $this->assertSame('1000.00', $journal->lines->firstWhere('account_id', $accounts['fixed_asset_cost'])->debit);
        $this->assertSame('200.00', $journal->lines->firstWhere('account_id', $accounts['fixed_asset_accumulated'])->credit);
    }

    private function seedOpeningAccounts(): array
    {
        $asset = $this->account('1888', 'Opening Cash', 'asset', 'debit', true);
        $liability = $this->account('2888', 'Opening Liability', 'liability', 'credit');
        $equity = $this->account('3888', 'Opening Equity', 'equity', 'credit');
        $revenue = $this->account('4888', 'Opening Revenue', 'revenue', 'credit');
        $expense = $this->account('6888', 'Opening Expense', 'expense', 'debit');
        $fixedAssetCost = $this->account('1588', 'Opening Fixed Asset Cost', 'asset', 'debit');
        $fixedAssetAccumulated = $this->account('1599', 'Opening Accumulated Depreciation', 'asset', 'credit');

        foreach ([
            'sales.accounts_receivable' => ['sales', $asset],
            'sales.revenue' => ['sales', $revenue],
            'sales.customer_deposit' => ['sales', $liability],
            'purchase.accounts_payable' => ['purchase', $liability],
            'purchase.expense' => ['purchase', $expense],
            'purchase.vendor_deposit' => ['purchase', $asset],
            'cash_bank.default_cash' => ['cash_bank', $asset],
            'cash_bank.default_bank' => ['cash_bank', $asset],
            'opening_balance.equity' => ['opening_balance', $equity],
            'fixed_assets.clearing' => ['fixed_assets', $fixedAssetCost],
            'fixed_assets.cost' => ['fixed_assets', $fixedAssetCost],
            'fixed_assets.accumulated_depreciation' => ['fixed_assets', $fixedAssetAccumulated],
            'fixed_assets.depreciation_expense' => ['fixed_assets', $expense],
            'fixed_assets.disposal_gain' => ['fixed_assets', $revenue],
            'fixed_assets.disposal_loss' => ['fixed_assets', $expense],
        ] as $key => [$module, $accountId]) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                [
                    'module' => $module,
                    'account_id' => $accountId,
                    'is_required' => true,
                    'is_active' => true,
                ]
            );
        }

        return [
            'asset' => $asset,
            'liability' => $liability,
            'equity' => $equity,
            'revenue' => $revenue,
            'expense' => $expense,
            'fixed_asset_cost' => $fixedAssetCost,
            'fixed_asset_accumulated' => $fixedAssetAccumulated,
        ];
    }

    private function account(string $code, string $name, string $type, string $normalBalance, bool $cashBank = false): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'is_cash_bank' => $cashBank,
            'is_active' => true,
            'is_system_default' => false,
        ])->id;
    }
}
