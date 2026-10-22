<?php

namespace Tests\Feature\Inventory;

use App\Modules\CashBank\Models\BankTransfer;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Warehouse;
use App\Shared\AccountMapping\AccountMappingKey;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyAccountingSetting;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Mode lock/open tahap 2 -- transfer bank, penyesuaian stok, mutasi stok
 * manual, dan stock opname.
 */
class TransactionEditModeInventoryTest extends JournalTestCase
{
    private const SETTINGS = [
        'transaction_workflow_mode' => 'draft_then_post',
        'auto_post_transactions' => false,
        'approval_enabled' => false,
    ];

    public function test_bank_transfer_edit_and_delete_follow_mode(): void
    {
        $ctx = $this->setUpTenant(accountingSettingOverrides: self::SETTINGS);
        $from = (int) $ctx['accounts']['debit'];
        $to = (int) ChartOfAccount::query()->create(['account_code' => '1010', 'account_name' => 'Bank', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_cash_bank' => true, 'is_active' => true, 'is_system_default' => false])->id;
        $payload = fn (float $amount): array => ['transfer_date' => '2026-01-12', 'from_cash_bank_account_id' => $from, 'to_cash_bank_account_id' => $to, 'amount' => $amount];

        $transfer = $this->postJson('/api/cash-bank/bank-transfers', $payload(3000), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/cash-bank/bank-transfers/'.$transfer['id'], $payload(3500), $ctx['headers'])->assertOk()->assertJsonPath('data.status', 'draft');
        $this->patchJson('/api/cash-bank/bank-transfers/'.$transfer['id'].'/post', [], $ctx['headers'])->assertOk();

        $this->patchJson('/api/cash-bank/bank-transfers/'.$transfer['id'], $payload(4000), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_LOCKED');

        $this->setMode($ctx['company'], 'open');
        $updated = $this->patchJson('/api/cash-bank/bank-transfers/'.$transfer['id'], $payload(4000), $ctx['headers'])->assertOk()->json('data');
        $this->assertSame('posted', $updated['status']);
        $this->assertEquals(4000, (float) $updated['amount']);

        $this->deleteJson('/api/cash-bank/bank-transfers/'.$transfer['id'], ['reason' => 'Salah akun'], $ctx['headers'])->assertOk();
        $this->assertNull(BankTransfer::query()->find($transfer['id']));
        $this->assertSame('void', BankTransfer::withTrashed()->find($transfer['id'])->status);
    }

    public function test_open_mode_edits_posted_stock_adjustment_and_rebalances_stock(): void
    {
        $ctx = $this->setUpTenant(accountingSettingOverrides: self::SETTINGS);
        $this->seedInventoryMappings();
        [$product, $warehouse, $unit] = $this->stockItem();
        $this->setMode($ctx['company'], 'open');

        $payload = fn (float $qty): array => [
            'adjustment_date' => '2026-01-10',
            'reason' => 'Init',
            'lines' => [['product_id' => $product, 'warehouse_id' => $warehouse, 'unit_id' => $unit, 'adjustment_type' => 'increase', 'quantity' => $qty, 'unit_cost' => 1000]],
        ];

        $adjustment = $this->postJson('/api/inventory/stock-adjustments', $payload(10), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/inventory/stock-adjustments/'.$adjustment['id'].'/post', [], $ctx['headers'])->assertOk();
        $this->assertSame(10.0, $this->onHand($product, $warehouse));

        $this->patchJson('/api/inventory/stock-adjustments/'.$adjustment['id'], $payload(4), $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');
        $this->assertSame(4.0, $this->onHand($product, $warehouse));

        $this->deleteJson('/api/inventory/stock-adjustments/'.$adjustment['id'], ['reason' => 'Batal'], $ctx['headers'])->assertOk();
        $this->assertSame(0.0, $this->onHand($product, $warehouse));
        $this->assertNull(StockAdjustment::query()->find($adjustment['id']));
    }

    public function test_manual_stock_movement_delete_and_generated_movement_is_protected(): void
    {
        $ctx = $this->setUpTenant(accountingSettingOverrides: self::SETTINGS);
        $this->seedInventoryMappings();
        [$product, $warehouse, $unit] = $this->stockItem();
        $this->setMode($ctx['company'], 'open');

        $movement = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [['product_id' => $product, 'warehouse_id' => $warehouse, 'unit_id' => $unit, 'quantity' => 6, 'unit_cost' => 500]],
        ], $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/inventory/stock-movements/'.$movement['id'].'/post', [], $ctx['headers'])->assertOk();
        $this->assertSame(6.0, $this->onHand($product, $warehouse));

        $this->deleteJson('/api/inventory/stock-movements/'.$movement['id'], ['reason' => 'Salah input'], $ctx['headers'])->assertOk();
        $this->assertSame(0.0, $this->onHand($product, $warehouse));
        $this->assertNull(StockMovement::query()->find($movement['id']));

        // Mutasi pembalik yang dibuat otomatis tidak boleh diubah langsung.
        $reversal = StockMovement::query()->where('source_type', 'reversal')->where('source_id', $movement['id'])->firstOrFail();
        $this->deleteJson('/api/inventory/stock-movements/'.$reversal->id, ['reason' => 'Coba'], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_HAS_DEPENDENCY');
    }

    public function test_draft_stock_opname_is_deleted_permanently(): void
    {
        $ctx = $this->setUpTenant(accountingSettingOverrides: self::SETTINGS);
        [, $warehouse] = $this->stockItem();

        $opname = $this->postJson('/api/inventory/stock-opnames', ['opname_date' => '2026-01-15', 'warehouse_id' => $warehouse], $ctx['headers'])->assertCreated()->json('data');
        $this->deleteJson('/api/inventory/stock-opnames/'.$opname['id'], [], $ctx['headers'])->assertOk();
        $this->assertNull(StockOpname::withTrashed()->find($opname['id']));
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function stockItem(): array
    {
        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        return [(int) $p->id, (int) $wh->id, (int) $unit->id];
    }

    private function onHand(int $product, int $warehouse): float
    {
        return (float) (StockBalance::query()->where('product_id', $product)->where('warehouse_id', $warehouse)->value('quantity_on_hand') ?? 0);
    }

    private function setMode(Company $company, string $mode): void
    {
        CompanyAccountingSetting::query()->where('company_id', $company->id)->update(['transaction_edit_mode' => $mode]);
    }

    private function seedInventoryMappings(): void
    {
        $make = fn (string $code, string $name, string $type, string $normal) => ChartOfAccount::query()->create(['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $inventory = $make('1400', 'Inventory', 'asset', 'debit');
        $cogs = $make('5100', 'COGS', 'expense', 'debit');
        $equity = $make('3000', 'Equity', 'equity', 'credit');
        $gain = $make('4100', 'Adj Gain', 'revenue', 'credit');
        $loss = $make('5200', 'Adj Loss', 'expense', 'debit');

        foreach ([
            AccountMappingKey::INVENTORY_ASSET => [$inventory, 'inventory'],
            AccountMappingKey::INVENTORY_COGS => [$cogs, 'inventory'],
            AccountMappingKey::OPENING_BALANCE_EQUITY => [$equity, 'opening_balance'],
            AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN => [$gain, 'inventory'],
            AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS => [$loss, 'inventory'],
        ] as $key => [$account, $module]) {
            AccountMapping::query()->create(['mapping_key' => $key, 'module' => $module, 'account_id' => $account->id, 'is_active' => true]);
        }
    }
}
