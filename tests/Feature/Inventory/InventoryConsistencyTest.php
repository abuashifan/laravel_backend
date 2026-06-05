<?php

namespace Tests\Feature\Inventory;

use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Services\Tenant\TenantContext;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Journal\JournalTestCase;

class InventoryConsistencyTest extends JournalTestCase
{
    public function test_stock_balance_valuation_stock_card_and_rebuild_are_consistent(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create([
            'product_code' => 'SKU1',
            'product_name' => 'Item',
            'product_type' => 'goods',
            'unit_id' => $unit->id,
            'is_stock_item' => true,
            'is_active' => true,
        ]);

        // Opening stock (posted) so stock balance & valuation have non-zero value.
        $m = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $mid = (int) $m->json('data.id');
        $this->patchJson('/api/inventory/stock-movements/'.$mid.'/post', [], $ctx['headers'])->assertStatus(200);

        $balance = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertEquals(10.0, (float) $balance->quantity_on_hand);
        $this->assertEquals(10000.0, (float) $balance->total_value);

        // Valuation total_value equals stock balance total_value
        $val = $this->getJson('/api/inventory/reports/valuation?include_zero=1', $ctx['headers'])->assertStatus(200);
        $this->assertEquals(10000.0, (float) $val->json('data.totals.total_value'));

        // Stock card ending equals stock balance.
        $card = $this->getJson('/api/inventory/reports/stock-card?product_id='.$p->id.'&warehouse_id='.$wh->id.'&start_date=2026-01-01&end_date=2026-01-31', $ctx['headers'])
            ->assertStatus(200);
        $this->assertEquals((float) $balance->quantity_on_hand, (float) $card->json('data.ending_quantity'));
        $this->assertEquals((float) $balance->total_value, (float) $card->json('data.ending_value'));

        // Rebuild stock balances should match the pre-rebuild snapshot.
        $snapshot = [
            'quantity_on_hand' => (float) $balance->quantity_on_hand,
            'average_cost' => (float) $balance->average_cost,
            'total_value' => (float) $balance->total_value,
        ];

        StockBalance::query()->delete();
        $this->assertSame(0, StockBalance::query()->count());

        Artisan::call('inventory:rebuild-stock-balances', ['--all' => true]);

        $rebuilt = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertEquals($snapshot['quantity_on_hand'], (float) $rebuilt->quantity_on_hand);
        $this->assertEquals($snapshot['average_cost'], (float) $rebuilt->average_cost);
        $this->assertEquals($snapshot['total_value'], (float) $rebuilt->total_value);
    }

    public function test_tenant_isolation_stock_balances_do_not_leak_between_companies(): void
    {
        $ctxA = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);
        $m = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ], $ctxA['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $m->json('data.id')).'/post', [], $ctxA['headers'])->assertStatus(200);
        $this->assertSame(1, StockBalance::query()->count());

        $ctxB = $this->setUpTenant(role: 'warehouse');

        $companyUser = CompanyUser::query()->where('company_id', $ctxB['company']->id)->where('user_id', $ctxB['user']->id)->firstOrFail();
        $tenantDb = TenantDatabase::query()->where('company_id', $ctxB['company']->id)->firstOrFail();
        app(TenantContext::class)->set($ctxB['company'], $companyUser, $tenantDb);

        $this->assertSame(0, StockBalance::query()->count());
        $this->getJson('/api/inventory/reports/stock-balances', $ctxB['headers'])->assertStatus(200)->assertJsonCount(0, 'data.rows');
    }

    private function seedInventoryMappings(): void
    {
        $inventory = ChartOfAccount::query()->create([
            'account_code' => '1400',
            'account_name' => 'Inventory',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $cogs = ChartOfAccount::query()->create([
            'account_code' => '5100',
            'account_name' => 'COGS',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $equity = ChartOfAccount::query()->create([
            'account_code' => '3000',
            'account_name' => 'Equity',
            'account_type' => 'equity',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $gain = ChartOfAccount::query()->create([
            'account_code' => '4100',
            'account_name' => 'Adj Gain',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);
        $loss = ChartOfAccount::query()->create([
            'account_code' => '5200',
            'account_name' => 'Adj Loss',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ASSET, 'module' => 'inventory', 'account_id' => $inventory->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_COGS, 'module' => 'inventory', 'account_id' => $cogs->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::OPENING_BALANCE_EQUITY, 'module' => 'opening_balance', 'account_id' => $equity->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_GAIN, 'module' => 'inventory', 'account_id' => $gain->id, 'is_active' => true]);
        AccountMapping::query()->create(['mapping_key' => AccountMappingKey::INVENTORY_ADJUSTMENT_LOSS, 'module' => 'inventory', 'account_id' => $loss->id, 'is_active' => true]);
    }
}
