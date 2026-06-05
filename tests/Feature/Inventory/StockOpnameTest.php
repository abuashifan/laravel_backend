<?php

namespace Tests\Feature\Inventory;

use App\Models\CompanyAccountingSetting;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
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

