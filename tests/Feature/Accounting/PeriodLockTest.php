<?php

namespace Tests\Feature\Accounting;

use App\Models\AccountingPeriod;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\Product;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Services\Accounting\FiscalYearService;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

class PeriodLockTest extends JournalTestCase
{
    public function test_post_invoice_with_date_in_closed_period_is_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: $this->dateFriendlySettings());
        $this->seedSalesMappings();
        $this->setPeriodStatus($ctx, 2026, 5, 'closed');

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(201)
            ->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');
    }

    public function test_post_invoice_with_date_in_open_period_succeeds(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: $this->dateFriendlySettings());
        $this->seedSalesMappings();
        $this->setPeriodStatus($ctx, 2026, 5, 'open');

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(201)
            ->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');
    }

    public function test_void_invoice_with_date_in_closed_period_is_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: $this->dateFriendlySettings());
        $this->seedSalesMappings();
        $this->setPeriodStatus($ctx, 2026, 5, 'open');

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(201)
            ->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200);

        $this->setPeriodStatus($ctx, 2026, 5, 'closed');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Wrong invoice'], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');
    }

    public function test_post_adjustment_with_date_in_closed_period_is_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: $this->dateFriendlySettings());
        $this->seedInventoryMappings();
        $this->setPeriodStatus($ctx, 2026, 5, 'closed');

        $adjustment = $this->postJson('/api/inventory/stock-adjustments', $this->stockAdjustmentPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->json('data');

        $this->patchJson('/api/inventory/stock-adjustments/'.$adjustment['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');
    }

    public function test_period_open_then_closed_blocks_later_post(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: $this->dateFriendlySettings());
        $this->seedSalesMappings();
        $this->setPeriodStatus($ctx, 2026, 5, 'open');

        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(201)
            ->json('data');

        $this->setPeriodStatus($ctx, 2026, 5, 'closed');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');
    }

    private function dateFriendlySettings(): array
    {
        return [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
            'approval_enabled' => false,
            'allow_future_transactions' => true,
            'max_future_days' => null,
            'allow_backdated_transactions' => true,
            'max_backdate_days' => null,
            'block_outside_current_fiscal_year' => false,
            'date_warning_enabled' => false,
        ];
    }

    private function setPeriodStatus(array $ctx, int $year, int $month, string $status): void
    {
        app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company'], $year);

        AccountingPeriod::query()
            ->where('company_id', $ctx['company']->id)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->update([
                'status' => $status,
                'closed_at' => $status === 'closed' ? now() : null,
                'closed_by' => $status === 'closed' ? $ctx['user']->id : null,
            ]);
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => Contact::query()->create([
                'name' => 'Customer A',
                'contact_type' => 'customer',
                'is_customer' => true,
                'is_active' => true,
            ])->id,
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => true,
            'tax_included' => false,
            'lines' => [
                ['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11],
            ],
        ], $overrides);
    }

    private function stockAdjustmentPayload(): array
    {
        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $warehouse = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $product = Product::query()->create([
            'product_code' => 'SKU1',
            'product_name' => 'Item',
            'product_type' => 'goods',
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'is_active' => true,
        ]);

        return [
            'adjustment_date' => '2026-05-20',
            'reason' => 'Period lock test',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'warehouse_id' => $warehouse->id,
                    'unit_id' => $unit->id,
                    'adjustment_type' => 'increase',
                    'quantity' => 1,
                    'unit_cost' => 1000,
                ],
            ],
        ];
    }

    private function seedSalesMappings(): void
    {
        $this->mapping(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE, 'sales', $this->account('1100', 'Accounts Receivable', 'asset', 'debit'));
        $this->mapping(AccountMappingKey::SALES_REVENUE, 'sales', $this->account('4100', 'Sales Revenue', 'revenue', 'credit'));
        $this->mapping(AccountMappingKey::SALES_TAX_OUTPUT, 'sales', $this->account('2100', 'Output Tax', 'liability', 'credit'));
        $this->mapping(AccountMappingKey::SALES_CUSTOMER_DEPOSIT, 'sales', $this->account('2200', 'Customer Deposit', 'liability', 'credit'));
        $this->mapping(AccountMappingKey::SALES_RETURN, 'sales', $this->account('4200', 'Sales Return', 'revenue', 'credit'));
    }

    private function seedInventoryMappings(): void
    {
        $this->mapping(AccountMappingKey::INVENTORY_ASSET, 'inventory', $this->account('1400', 'Inventory', 'asset', 'debit'));
        $this->mapping(AccountMappingKey::INVENTORY_COGS, 'inventory', $this->account('5100', 'COGS', 'expense', 'debit'));
        $this->mapping(AccountMappingKey::OPENING_BALANCE_EQUITY, 'opening_balance', $this->account('3000', 'Equity', 'equity', 'credit'));
        $this->mapping(AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN, 'inventory', $this->account('4300', 'Adjustment Gain', 'revenue', 'credit'));
        $this->mapping(AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS, 'inventory', $this->account('5200', 'Adjustment Loss', 'expense', 'debit'));
    }

    private function account(string $code, string $name, string $type, string $normalBalance): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'is_active' => true,
            'is_system_default' => false,
        ])->id;
    }

    private function mapping(string $key, string $module, int $accountId): void
    {
        AccountMapping::query()->create([
            'mapping_key' => $key,
            'module' => $module,
            'account_id' => $accountId,
            'is_required' => true,
            'is_active' => true,
        ]);
    }
}
