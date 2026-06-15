<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Models\CompanySetupState;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Services\Settings\CompanySettingService;
use Tests\Feature\Journal\JournalTestCase;

class SetupWizardTest extends JournalTestCase
{
    public function test_setup_routes_require_setup_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_status_creates_default_state_and_current_step_can_store_opening_date(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.state.status', 'not_started')
            ->assertJsonPath('data.state.current_step', 'company_profile');

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'accounting_settings',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.state.status', 'in_progress')
            ->assertJsonPath('data.state.current_step', 'accounting_settings')
            ->assertJsonPath('data.state.opening_date', '2026-01-01');
    }

    public function test_validate_all_blocks_when_opening_balance_batch_is_missing(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $this->seedSetupCoaAndMappings();

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'final_review',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertOk();

        $response = $this->postJson('/api/setup/validate-all', [], $ctx['headers'])
            ->assertOk();

        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.results.opening_balance_preview.errors.0.code', 'OPENING_BALANCE_BATCH_REQUIRED');
        $response->assertJsonPath('data.state.status', 'in_progress');
    }

    public function test_finalized_setup_cannot_be_downgraded_by_stale_current_step_request(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        CompanySetupState::query()->create([
            'company_id' => $ctx['company']->id,
            'status' => 'finalized',
            'current_step' => 'finalized',
            'completed_steps' => ['company_profile', 'finalized'],
            'validation_errors' => [],
            'finalized_at' => now(),
            'finalized_by' => $ctx['user']->id,
        ]);

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'company_profile',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SETUP_ALREADY_FINALIZED');
    }

    private function seedSetupCoaAndMappings(): void
    {
        $asset = $this->account('1999', 'Setup Asset', 'asset', 'debit', true);
        $liability = $this->account('2999', 'Setup Payable', 'liability', 'credit');
        $equity = $this->account('3999', 'Setup Equity', 'equity', 'credit');
        $revenue = $this->account('4999', 'Setup Revenue', 'revenue', 'credit');
        $expense = $this->account('6999', 'Setup Expense', 'expense', 'debit');

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
