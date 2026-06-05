<?php

namespace Tests\Feature\Inventory;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\StockMovementLine;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

class AverageCostValuationTest extends JournalTestCase
{
    public function test_moving_average_and_outgoing_valuation_and_sales_out_journal_uses_average_cost(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        // First incoming: 10 @ 1000 => avg 1000, value 10000
        $m1 = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $m1->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);

        // Second incoming: 10 @ 2000 => avg 1500, value 30000
        $m2 = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-02',
            'movement_type' => 'adjustment_in',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 2000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $m2->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(20.0, (float) $bal->quantity_on_hand);
        $this->assertSame(1500.0, (float) $bal->average_cost);
        $this->assertSame(30000.0, (float) $bal->total_value);

        // Outgoing uses avg 1500: 4 units => 6000
        $m3 = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-03',
            'movement_type' => 'sales_out',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 4, 'unit_cost' => 0],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $m3id = (int) $m3->json('data.id');
        $this->patchJson('/api/inventory/stock-movements/'.$m3id.'/post', [], $ctx['headers'])->assertStatus(200);

        $m3m = StockMovement::query()->with('lines')->findOrFail($m3id);
        $this->assertSame(6000.0, (float) $m3m->total_value);
        $line = $m3m->lines->first();
        $this->assertSame(1500.0, (float) $line->unit_cost);
        $this->assertSame(6000.0, (float) $line->total_cost);
        $this->assertSame(20.0, (float) $line->quantity_before);
        $this->assertSame(16.0, (float) $line->quantity_after);

        $bal->refresh();
        $this->assertSame(16.0, (float) $bal->quantity_on_hand);
        $this->assertSame(24000.0, (float) $bal->total_value);
        $this->assertSame(1500.0, (float) $bal->average_cost);

        // Sales out journal should use movement total_value (COGS)
        $je = JournalEntry::query()->where('source_type', 'stock_movement')->where('source_id', $m3id)->firstOrFail();
        $je->load('lines');
        $this->assertSame(6000.0, (float) $je->lines->sum('debit'));
        $this->assertSame(6000.0, (float) $je->lines->sum('credit'));

        // Valuation endpoint
        $val = $this->getJson('/api/inventory/valuation?include_zero=1', $ctx['headers'])->assertStatus(200);
        $val->assertJsonPath('data.totals.total_value', 24000);
    }

    public function test_valuation_excludes_draft_and_void_movements_via_reversal(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $draft = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 5, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $this->assertSame('draft', (string) $draft->json('data.status'));
        $this->assertSame(0, StockBalance::query()->count());

        $this->patchJson('/api/inventory/stock-movements/'.((int) $draft->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);
        $this->assertSame(5.0, (float) StockBalance::query()->firstOrFail()->quantity_on_hand);

        $m = StockMovement::query()->firstOrFail();
        $this->patchJson('/api/inventory/stock-movements/'.$m->id.'/void', ['reason' => 'mistake'], $ctx['headers'])->assertStatus(200);

        $this->assertSame(0.0, (float) StockBalance::query()->firstOrFail()->quantity_on_hand);
    }

    private function seedInventoryMappings(): void
    {
        $inventory = ChartOfAccount::query()->create(['account_code' => '1400', 'account_name' => 'Inventory', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $cogs = ChartOfAccount::query()->create(['account_code' => '5100', 'account_name' => 'COGS', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $equity = ChartOfAccount::query()->create(['account_code' => '3000', 'account_name' => 'Equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $gain = ChartOfAccount::query()->create(['account_code' => '4100', 'account_name' => 'Adj Gain', 'account_type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $loss = ChartOfAccount::query()->create(['account_code' => '5200', 'account_name' => 'Adj Loss', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);

        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ASSET, 'module' => 'inventory', 'account_id' => $inventory->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_COGS, 'module' => 'inventory', 'account_id' => $cogs->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY, 'module' => 'opening_balance', 'account_id' => $equity->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN, 'module' => 'inventory', 'account_id' => $gain->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS, 'module' => 'inventory', 'account_id' => $loss->id, 'is_active' => true]);
    }
}
