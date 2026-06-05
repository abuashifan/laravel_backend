<?php

namespace Tests\Feature\Inventory;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Journal\JournalTestCase;

class StockBalanceTest extends JournalTestCase
{
    public function test_unauthenticated_rejected(): void
    {
        auth()->logout();
        $this->getJson('/api/inventory/stock-balances')->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'warehouse');
        $this->getJson('/api/inventory/stock-balances')->assertStatus(422);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');
        $this->getJson('/api/inventory/stock-balances', $ctx['headers'])->assertStatus(403);
    }

    public function test_posted_movements_update_stock_balance_and_void_creates_reversal_effect(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $m = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $mid = (int) $m->json('data.id');
        $this->patchJson('/api/inventory/stock-movements/'.$mid.'/post', [], $ctx['headers'])->assertStatus(200);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->first();
        $this->assertNotNull($bal);
        $this->assertSame(10.0, (float) $bal->quantity_on_hand);

        $out = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-11',
            'movement_type' => 'adjustment_out',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $outId = (int) $out->json('data.id');
        $this->patchJson('/api/inventory/stock-movements/'.$outId.'/post', [], $ctx['headers'])->assertStatus(200);

        $bal->refresh();
        $this->assertSame(9.0, (float) $bal->quantity_on_hand);

        $this->patchJson('/api/inventory/stock-movements/'.$outId.'/void', ['reason' => 'mistake'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');

        $bal->refresh();
        $this->assertSame(10.0, (float) $bal->quantity_on_hand);
    }

    public function test_insufficient_stock_rejected_when_negative_disabled_and_allowed_when_enabled(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $m = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 2, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $m->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);

        $out = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-11',
            'movement_type' => 'adjustment_out',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $outId = (int) $out->json('data.id');

        $res = $this->patchJson('/api/inventory/stock-movements/'.$outId.'/post', [], $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'INSUFFICIENT_STOCK');

        Config::set('inventory.allow_negative_stock', true);
        $this->patchJson('/api/inventory/stock-movements/'.$outId.'/post', [], $ctx['headers'])->assertStatus(200);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(-8.0, (float) $bal->quantity_on_hand);
    }

    public function test_rebuild_command_rebuilds_stock_balances_from_posted_movements(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $m = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 3, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $this->patchJson('/api/inventory/stock-movements/'.((int) $m->json('data.id')).'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertDatabaseHas('stock_balances', ['product_id' => $p->id, 'warehouse_id' => $wh->id], 'tenant');

        StockBalance::query()->delete();
        $this->assertSame(0, StockBalance::query()->count());

        Artisan::call('inventory:rebuild-stock-balances', ['--all' => true]);

        $bal = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(3.0, (float) $bal->quantity_on_hand);
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

