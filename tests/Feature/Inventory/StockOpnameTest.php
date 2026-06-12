<?php

namespace Tests\Feature\Inventory;

use App\Models\CompanyAccountingSetting;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockOpname;
use App\Models\Tenant\StockOpnameLine;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Services\Accounting\FiscalYearService;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Journal\JournalTestCase;

class StockOpnameTest extends JournalTestCase
{
    public function test_finalize_opname_in_creates_inventory_gain_journal(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $accounts = $this->seedInventoryMappings();
        [$unit, $warehouse, $product, $opname, $line] = $this->stockOpnameFixture($ctx['headers'], systemQty: 10, physicalQty: 12, averageCost: 1000);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $ctx['headers'])->assertStatus(200);

        $movement = StockMovement::query()->with('journalEntry.lines')->where('source_type', 'stock_opname')->where('source_id', $opname->id)->firstOrFail();
        $balance = StockBalance::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->firstOrFail();

        $this->assertSame('opname_in', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);
        $this->assertSame(12.0, (float) $balance->quantity_on_hand);
        $this->assertNotNull($movement->journal_entry_id);
        $this->assertSame((int) $movement->journal_entry_id, (int) $movement->journalEntry->id);
        $this->assertSame('stock_movement', (string) $movement->journalEntry->source_type);
        $this->assertSame((string) $movement->id, (string) $movement->journalEntry->source_id);
        $this->assertSame('inventory', (string) $movement->journalEntry->source_module);
        $this->assertStringContainsString('Stock Opname Increase', (string) $movement->journalEntry->description);

        $this->assertJournalLine($movement->journalEntry, (int) $accounts['inventory']->id, 2000, 0);
        $this->assertJournalLine($movement->journalEntry, (int) $accounts['gain']->id, 0, 2000);
        $this->assertJournalBalances($movement->journalEntry);

        $stockLine = $movement->lines()->firstOrFail();
        $this->assertSame(2000.0, (float) $stockLine->total_cost);
        $this->assertSame((int) $line->id, (int) $stockLine->source_line_id);
        $this->assertSame((int) $unit->id, (int) $stockLine->unit_id);
    }

    public function test_finalize_opname_out_creates_inventory_loss_journal(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $accounts = $this->seedInventoryMappings();
        [, $warehouse, $product, $opname] = $this->stockOpnameFixture($ctx['headers'], systemQty: 10, physicalQty: 8, averageCost: 1000);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $ctx['headers'])->assertStatus(200);

        $movement = StockMovement::query()->with('journalEntry.lines')->where('source_type', 'stock_opname')->where('source_id', $opname->id)->firstOrFail();
        $balance = StockBalance::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->firstOrFail();

        $this->assertSame('opname_out', (string) $movement->movement_type);
        $this->assertSame('posted', (string) $movement->status);
        $this->assertSame(8.0, (float) $balance->quantity_on_hand);
        $this->assertNotNull($movement->journal_entry_id);
        $this->assertStringContainsString('Stock Opname Decrease', (string) $movement->journalEntry->description);

        $this->assertJournalLine($movement->journalEntry, (int) $accounts['loss']->id, 2000, 0);
        $this->assertJournalLine($movement->journalEntry, (int) $accounts['inventory']->id, 0, 2000);
        $this->assertJournalBalances($movement->journalEntry);
    }

    public function test_opname_in_fails_when_inventory_asset_mapping_missing_and_no_line_or_product_account(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings(omit: [AccountMappingKey::INVENTORY_ASSET]);
        [, $warehouse, $product, $opname] = $this->stockOpnameFixture($ctx['headers'], systemQty: 10, physicalQty: 12, averageCost: 1000);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $res = $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $ctx['headers']);

        $res->assertStatus(422);
        $res->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING');
        $this->assertStringContainsString('Inventory Asset account mapping is not configured', (string) $res->json('message'));
        $this->assertDatabaseMissing('stock_movements', ['source_type' => 'stock_opname', 'source_id' => $opname->id], 'tenant');
        $this->assertSame(10.0, (float) StockBalance::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->firstOrFail()->quantity_on_hand);
    }

    public function test_opname_uses_product_inventory_account_before_global_inventory_mapping(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $accounts = $this->seedInventoryMappings(omit: [AccountMappingKey::INVENTORY_ASSET]);
        [, , $product, $opname] = $this->stockOpnameFixture($ctx['headers'], systemQty: 10, physicalQty: 12, averageCost: 1000);
        $product->inventory_account_id = $accounts['inventory']->id;
        $product->save();

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $ctx['headers'])->assertStatus(200);

        $movement = StockMovement::query()->with('journalEntry.lines')->where('source_type', 'stock_opname')->where('source_id', $opname->id)->firstOrFail();

        $this->assertJournalLine($movement->journalEntry, (int) $accounts['inventory']->id, 2000, 0);
        $this->assertJournalLine($movement->journalEntry, (int) $accounts['gain']->id, 0, 2000);
        $this->assertJournalBalances($movement->journalEntry);
    }

    public function test_opname_in_fails_when_adjustment_gain_mapping_missing(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings(omit: [AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN]);
        [, , , $opname] = $this->stockOpnameFixture($ctx['headers'], systemQty: 10, physicalQty: 12, averageCost: 1000);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $res = $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $ctx['headers']);

        $res->assertStatus(422);
        $res->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING');
        $this->assertStringContainsString('Stock Adjustment Gain account mapping is not configured', (string) $res->json('message'));
    }

    public function test_opname_out_fails_when_adjustment_loss_mapping_missing(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings(omit: [AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS]);
        [, , , $opname] = $this->stockOpnameFixture($ctx['headers'], systemQty: 10, physicalQty: 8, averageCost: 1000);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $res = $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $ctx['headers']);

        $res->assertStatus(422);
        $res->assertJsonPath('code', 'ACCOUNT_MAPPING_MISSING');
        $this->assertStringContainsString('Stock Adjustment Loss account mapping is not configured', (string) $res->json('message'));
    }

    public function test_reposting_posted_opname_movement_does_not_create_double_journal(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();
        [, , , $opname] = $this->stockOpnameFixture($ctx['headers'], systemQty: 10, physicalQty: 12, averageCost: 1000);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $ctx['headers'])->assertStatus(200);

        $movement = StockMovement::query()->where('source_type', 'stock_opname')->where('source_id', $opname->id)->firstOrFail();
        $journalId = (int) $movement->journal_entry_id;
        $journalCount = JournalEntry::query()->count();

        $this->patchJson('/api/inventory/stock-movements/'.$movement->id.'/post', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'DOCUMENT_ALREADY_POSTED');

        $movement->refresh();
        $this->assertSame($journalId, (int) $movement->journal_entry_id);
        $this->assertSame($journalCount, JournalEntry::query()->count());
    }

    public function test_void_opname_creates_reversal_journal_with_opposite_effect(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $accounts = $this->seedInventoryMappings();
        [, $warehouse, $product, $opname] = $this->stockOpnameFixture($ctx['headers'], systemQty: 10, physicalQty: 8, averageCost: 1000);

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/finalize', [], $ctx['headers'])->assertStatus(200);

        $original = StockMovement::query()->where('source_type', 'stock_opname')->where('source_id', $opname->id)->firstOrFail();

        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/void', ['reason' => 'wrong count'], $ctx['headers'])->assertStatus(200);

        $original->refresh();
        $reversal = StockMovement::query()->with('journalEntry.lines')->findOrFail((int) $original->reversed_by_id);
        $balance = StockBalance::query()->where('product_id', $product->id)->where('warehouse_id', $warehouse->id)->firstOrFail();

        $this->assertSame('opname_out', (string) $reversal->movement_type);
        $this->assertSame('in', (string) $reversal->direction);
        $this->assertSame(10.0, (float) $balance->quantity_on_hand);
        $this->assertNotNull($reversal->journal_entry_id);
        $this->assertStringContainsString('Reversal Stock Opname Decrease', (string) $reversal->journalEntry->description);
        $this->assertJournalLine($reversal->journalEntry, (int) $accounts['inventory']->id, 2000, 0);
        $this->assertJournalLine($reversal->journalEntry, (int) $accounts['loss']->id, 0, 2000);
        $this->assertJournalBalances($reversal->journalEntry);
    }

    public function test_create_generate_count_finalize_void_flow(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        // create opening stock via movement API to seed balance
        $m = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $m->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);

        $op = $this->postJson('/api/inventory/stock-opnames', [
            'opname_date' => '2026-01-10',
            'warehouse_id' => $wh->id,
        ], $ctx['headers'])->assertStatus(201);
        $id = (int) $op->json('data.id');

        $this->postJson('/api/inventory/stock-opnames/'.$id.'/generate-lines', [], $ctx['headers'])->assertStatus(200);
        $opname = StockOpname::query()->with('lines')->findOrFail($id);
        $this->assertCount(1, $opname->lines);
        $lineId = (int) $opname->lines->first()->id;

        // Physical counted less (difference -2 => opname_out)
        $this->patchJson('/api/inventory/stock-opnames/'.$id.'/lines/'.$lineId, [
            'physical_quantity' => 8,
            'reason' => 'shrinkage',
        ], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/inventory/stock-opnames/'.$id.'/counted', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$id.'/finalize', [], $ctx['headers'])->assertStatus(200);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(8.0, (float) $bal->quantity_on_hand);

        // cannot edit finalized
        $this->patchJson('/api/inventory/stock-opnames/'.$id.'/lines/'.$lineId, [
            'physical_quantity' => 7,
        ], $ctx['headers'])->assertStatus(422);

        // void should revert (reversal)
        $this->patchJson('/api/inventory/stock-opnames/'.$id.'/void', ['reason' => 'mistake'], $ctx['headers'])->assertStatus(200);
        $bal->refresh();
        $this->assertSame(10.0, (float) $bal->quantity_on_hand);
    }

    public function test_period_lock_blocks_finalize(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $companyId = (int) $ctx['company']->id;
        $setting = CompanyAccountingSetting::query()->where('company_id', $companyId)->firstOrFail();
        $setting->allow_future_transactions = true;
        $setting->max_future_days = null;
        $setting->save();

        $fy = app(FiscalYearService::class)->getOrCreateActiveFiscalYear($ctx['company']);
        $fy->locked_until = '2026-01-31';
        $fy->save();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $m = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $m->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);

        $op = $this->postJson('/api/inventory/stock-opnames', [
            'opname_date' => '2026-01-10',
            'warehouse_id' => $wh->id,
        ], $ctx['headers'])->assertStatus(201);
        $id = (int) $op->json('data.id');
        $this->postJson('/api/inventory/stock-opnames/'.$id.'/generate-lines', [], $ctx['headers'])->assertStatus(200);

        $line = StockOpnameLine::query()->where('stock_opname_id', $id)->firstOrFail();
        $this->patchJson('/api/inventory/stock-opnames/'.$id.'/lines/'.$line->id, [
            'physical_quantity' => 2,
        ], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-opnames/'.$id.'/counted', [], $ctx['headers'])->assertStatus(200);

        $res = $this->patchJson('/api/inventory/stock-opnames/'.$id.'/finalize', [], $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');
        $this->postJson('/api/inventory/stock-opnames', [
            'opname_date' => '2026-01-10',
            'warehouse_id' => 1,
        ], $ctx['headers'])->assertStatus(403);
    }

    private function stockOpnameFixture(array $headers, float $systemQty, float $physicalQty, float $averageCost): array
    {
        $unit = Unit::query()->create(['code' => 'PCS'.uniqid(), 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $warehouse = Warehouse::query()->create(['code' => 'WH'.uniqid(), 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $product = Product::query()->create([
            'product_code' => 'SKU'.uniqid(),
            'product_name' => 'Item',
            'product_type' => 'goods',
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'is_active' => true,
        ]);

        StockBalance::query()->create([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity_on_hand' => $systemQty,
            'quantity_available' => $systemQty,
            'quantity_reserved' => 0,
            'average_cost' => $averageCost,
            'total_value' => round($systemQty * $averageCost, 2),
        ]);

        $op = $this->postJson('/api/inventory/stock-opnames', [
            'opname_date' => '2026-01-10',
            'warehouse_id' => $warehouse->id,
        ], $headers)->assertStatus(201);
        $opname = StockOpname::query()->findOrFail((int) $op->json('data.id'));

        $this->postJson('/api/inventory/stock-opnames/'.$opname->id.'/generate-lines', [], $headers)->assertStatus(200);
        $line = StockOpnameLine::query()->where('stock_opname_id', $opname->id)->firstOrFail();
        $this->patchJson('/api/inventory/stock-opnames/'.$opname->id.'/lines/'.$line->id, [
            'physical_quantity' => $physicalQty,
        ], $headers)->assertStatus(200);

        return [$unit, $warehouse, $product, $opname->refresh(), $line->refresh()];
    }

    private function seedInventoryMappings(array $omit = []): array
    {
        $inventory = ChartOfAccount::query()->create(['account_code' => '1400', 'account_name' => 'Inventory', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $cogs = ChartOfAccount::query()->create(['account_code' => '5100', 'account_name' => 'COGS', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $equity = ChartOfAccount::query()->create(['account_code' => '3000', 'account_name' => 'Equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $gain = ChartOfAccount::query()->create(['account_code' => '4100', 'account_name' => 'Adj Gain', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $loss = ChartOfAccount::query()->create(['account_code' => '5200', 'account_name' => 'Adj Loss', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);

        foreach ([
            AccountMappingKey::INVENTORY_ASSET => ['inventory', $inventory->id],
            AccountMappingKey::INVENTORY_COGS => ['inventory', $cogs->id],
            AccountMappingKey::OPENING_BALANCE_EQUITY => ['opening_balance', $equity->id],
            AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN => ['inventory', $gain->id],
            AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS => ['inventory', $loss->id],
        ] as $key => [$module, $accountId]) {
            if (in_array($key, $omit, true)) {
                continue;
            }

            AccountMapping::query()->create(['mapping_key' => $key, 'module' => $module, 'account_id' => $accountId, 'is_active' => true]);
        }

        return compact('inventory', 'cogs', 'equity', 'gain', 'loss');
    }

    private function assertJournalLine(JournalEntry $journal, int $accountId, float $debit, float $credit): void
    {
        $line = $journal->lines->first(fn ($line): bool => (int) $line->account_id === $accountId && (float) $line->debit === (float) $debit && (float) $line->credit === (float) $credit);

        $this->assertNotNull($line, "Missing journal line account {$accountId} debit {$debit} credit {$credit}");
    }

    private function assertJournalBalances(JournalEntry $journal): void
    {
        $journal->loadMissing('lines');

        $this->assertSame(
            round((float) $journal->lines->sum('debit'), 2),
            round((float) $journal->lines->sum('credit'), 2),
        );
    }
}
