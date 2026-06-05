<?php

namespace Tests\Feature\Inventory;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductCategory;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Journal\JournalTestCase;

class InventoryReportTest extends JournalTestCase
{
    public function test_stock_balance_and_movement_and_stock_card_and_valuation_reports_work(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $category = ProductCategory::query()->create(['name' => 'Parts', 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'product_category_id' => $category->id, 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        // draft movement excluded
        $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $movRes = $this->getJson('/api/inventory/reports/stock-movements', $ctx['headers'])->assertStatus(200);
        $this->assertSame(0, count((array) $movRes->json('data.rows')));

        $draftId = (int) StockMovement::query()->firstOrFail()->id;
        $this->patchJson('/api/inventory/stock-movements/'.$draftId.'/post', [], $ctx['headers'])->assertStatus(200);

        $balRes = $this->getJson('/api/inventory/reports/stock-balances?include_zero=1', $ctx['headers'])->assertStatus(200);
        $this->assertEquals(10.0, (float) $balRes->json('data.rows.0.quantity_on_hand'));

        $movRes2 = $this->getJson('/api/inventory/reports/stock-movements', $ctx['headers'])->assertStatus(200);
        $this->assertSame(1, count((array) $movRes2->json('data.rows')));

        // Stock card should reflect ending quantity/value
        $card = $this->getJson('/api/inventory/reports/stock-card?product_id='.$p->id.'&warehouse_id='.$wh->id.'&start_date=2026-01-01&end_date=2026-01-31', $ctx['headers'])
            ->assertStatus(200);
        $this->assertEquals(10.0, (float) $card->json('data.ending_quantity'));
        $this->assertEquals(10000.0, (float) $card->json('data.ending_value'));
        $this->assertSame('Main', $card->json('data.movements.0.warehouse_name'));

        $categoryCard = $this->getJson('/api/inventory/reports/stock-card?category_id='.$category->id.'&warehouse_id='.$wh->id.'&start_date=2026-01-01&end_date=2026-01-31', $ctx['headers'])
            ->assertStatus(200);
        $this->assertEquals(10.0, (float) $categoryCard->json('data.ending_quantity'));

        // Valuation report totals equals stock balance totals
        $val = $this->getJson('/api/inventory/reports/valuation?include_zero=1', $ctx['headers'])->assertStatus(200);
        $this->assertEquals(10000.0, (float) $val->json('data.totals.total_value'));
    }

    public function test_void_excluded_unless_include_void_true(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

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
        $mid = (int) $m->json('data.id');
        $posted = $this->patchJson('/api/inventory/stock-movements/'.$mid.'/post', [], $ctx['headers'])->assertStatus(200);
        $origNumber = (string) $posted->json('data.movement_number');

        $this->patchJson('/api/inventory/stock-movements/'.$mid.'/void', ['reason' => 'mistake'], $ctx['headers'])->assertStatus(200);

        $rep = $this->getJson('/api/inventory/reports/stock-movements', $ctx['headers'])->assertStatus(200);
        $numbers = array_map(fn ($r) => (string) ($r['movement_number'] ?? ''), (array) $rep->json('data.rows'));
        $this->assertFalse(in_array($origNumber, $numbers, true));

        $rep2 = $this->getJson('/api/inventory/reports/stock-movements?include_void=1', $ctx['headers'])->assertStatus(200);
        $numbers2 = array_map(fn ($r) => (string) ($r['movement_number'] ?? ''), (array) $rep2->json('data.rows'));
        $this->assertTrue(in_array($origNumber, $numbers2, true));
    }

    public function test_negative_stock_report_works(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();
        Config::set('inventory.allow_negative_stock', true);

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $out = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-01',
            'movement_type' => 'adjustment_out',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 0],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $out->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);

        $neg = $this->getJson('/api/inventory/reports/negative-stock', $ctx['headers'])->assertStatus(200);
        $this->assertGreaterThanOrEqual(1, count((array) $neg->json('data.rows')));
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');
        $this->getJson('/api/inventory/reports/stock-balances', $ctx['headers'])->assertStatus(403);
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
