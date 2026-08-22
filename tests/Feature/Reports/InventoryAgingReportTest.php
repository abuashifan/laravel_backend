<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Warehouse;
use App\Shared\AccountMapping\AccountMappingKey;
use Tests\Feature\Journal\JournalTestCase;

class InventoryAgingReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/inventory/reports/aging')->assertStatus(401);
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');
        $this->getJson('/api/inventory/reports/aging', $ctx['headers'])->assertStatus(403);
    }

    public function test_invalid_as_of_date_returns_422(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->getJson('/api/inventory/reports/aging?as_of_date=not-a-date', $ctx['headers'])->assertStatus(422);
    }

    public function test_aging_buckets_by_last_inbound_date(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $recent = Product::query()->create(['product_code' => 'SKU-NEW', 'product_name' => 'Recent Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);
        $old = Product::query()->create(['product_code' => 'SKU-OLD', 'product_name' => 'Old Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $this->postOpeningStock($ctx, $recent->id, $wh->id, $unit->id, '2026-06-01', 10, 1000);
        $this->postOpeningStock($ctx, $old->id, $wh->id, $unit->id, '2026-01-01', 5, 200);

        // as_of 2026-06-15: recent age = 14d (0-30 bucket), old age = 165d (>90 bucket).
        $res = $this->getJson('/api/inventory/reports/aging?as_of_date=2026-06-15', $ctx['headers'])->assertStatus(200);

        $rows = collect($res->json('data.rows'))->keyBy('product_code');
        $this->assertEqualsWithDelta(10000.0, (float) $rows['SKU-NEW']['buckets']['0_30'], 0.001);
        $this->assertEquals(0.0, (float) $rows['SKU-NEW']['buckets']['over_90']);
        $this->assertEqualsWithDelta(1000.0, (float) $rows['SKU-OLD']['buckets']['over_90'], 0.001);
        $this->assertSame('2026-06-01', $rows['SKU-NEW']['last_inbound_date']);

        $totals = $res->json('data.totals');
        $this->assertEqualsWithDelta(11000.0, (float) $totals['total_value'], 0.001);
        $this->assertEqualsWithDelta(10000.0, (float) $totals['buckets']['0_30'], 0.001);
        $this->assertEqualsWithDelta(1000.0, (float) $totals['buckets']['over_90'], 0.001);
        $this->assertSame('2026-06-15', $res->json('data.as_of_date'));
    }

    public function test_warehouse_filter_narrows_rows(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh1 = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $wh2 = Warehouse::query()->create(['code' => 'WH2', 'name' => 'Second', 'is_default' => false, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $this->postOpeningStock($ctx, $p->id, $wh1->id, $unit->id, '2026-01-01', 3, 100);
        $this->postOpeningStock($ctx, $p->id, $wh2->id, $unit->id, '2026-01-01', 7, 100);

        $res = $this->getJson('/api/inventory/reports/aging?warehouse_id='.$wh2->id, $ctx['headers'])->assertStatus(200);
        $rows = $res->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($wh2->id, $rows[0]['warehouse_id']);
        $this->assertEquals(7.0, (float) $rows[0]['quantity_on_hand']);
    }

    private function postOpeningStock(array $ctx, int $productId, int $warehouseId, int $unitId, string $date, float $qty, float $cost): void
    {
        $res = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => $date,
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $productId, 'warehouse_id' => $warehouseId, 'unit_id' => $unitId, 'quantity' => $qty, 'unit_cost' => $cost],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $this->patchJson('/api/inventory/stock-movements/'.((int) $res->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);
    }

    private function seedInventoryMappings(): void
    {
        $inventory = ChartOfAccount::query()->create(['account_code' => '1400', 'account_name' => 'Inventory', 'account_type' => 'asset', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $cogs = ChartOfAccount::query()->create(['account_code' => '5100', 'account_name' => 'COGS', 'account_type' => 'expense', 'normal_balance' => 'debit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);
        $equity = ChartOfAccount::query()->create(['account_code' => '3000', 'account_name' => 'Equity', 'account_type' => 'equity', 'normal_balance' => 'credit', 'is_cash_bank' => false, 'is_active' => true, 'is_system_default' => false]);

        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ASSET, 'module' => 'inventory', 'account_id' => $inventory->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_COGS, 'module' => 'inventory', 'account_id' => $cogs->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY, 'module' => 'opening_balance', 'account_id' => $equity->id, 'is_active' => true]);
    }
}
