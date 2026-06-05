<?php

namespace Tests\Feature\Inventory;

use App\Models\CompanyAccountingSetting;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Services\Accounting\FiscalYearService;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Journal\JournalTestCase;

class StockAdjustmentTest extends JournalTestCase
{
    public function test_create_update_approve_post_void_adjustment_flow(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_approve_post',
            'auto_post_transactions' => false,
            'approval_enabled' => true,
        ]);
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $res = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'reason' => 'Init',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'adjustment_type' => 'increase', 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $id = (int) $res->json('data.id');
        $this->patchJson('/api/inventory/stock-adjustments/'.$id, [
            'reason' => 'Updated',
        ], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/inventory/stock-adjustments/'.$id.'/approve', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/inventory/stock-adjustments/'.$id.'/post', [], $ctx['headers'])->assertStatus(200);

        $adj = StockAdjustment::query()->findOrFail($id);
        $this->assertSame('posted', (string) $adj->status);

        $balance = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(10.0, (float) $balance->quantity_on_hand);
        $this->assertSame(1000.0, (float) $balance->average_cost);

        $movement = StockMovement::query()->where('source_type', 'stock_adjustment')->where('source_id', $adj->id)->firstOrFail();
        $this->assertSame('posted', (string) $movement->status);

        // void should reverse movement impact
        $this->patchJson('/api/inventory/stock-adjustments/'.$id.'/void', ['reason' => 'mistake'], $ctx['headers'])->assertStatus(200);
        $balance->refresh();
        $this->assertSame(0.0, (float) $balance->quantity_on_hand);
    }

    public function test_cannot_decrease_more_than_available_when_negative_disabled(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
            'approval_enabled' => false,
        ]);
        $this->seedInventoryMappings();
        Config::set('inventory.allow_negative_stock', false);

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $adj = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'adjustment_type' => 'decrease', 'quantity' => 1],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $id = (int) $adj->json('data.id');
        $res = $this->patchJson('/api/inventory/stock-adjustments/'.$id.'/post', [], $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'INSUFFICIENT_STOCK');
    }

    public function test_period_lock_blocks_posting_adjustment(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_approve_post',
            'auto_post_transactions' => false,
            'approval_enabled' => true,
        ]);
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

        $adj = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'adjustment_type' => 'increase', 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $id = (int) $adj->json('data.id');
        $this->patchJson('/api/inventory/stock-adjustments/'.$id.'/approve', [], $ctx['headers'])->assertStatus(200);

        $res = $this->patchJson('/api/inventory/stock-adjustments/'.$id.'/post', [], $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');
    }

    public function test_simple_auto_post_without_approval_posts_adjustment_on_create(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
            'approval_enabled' => false,
        ]);
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $res = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'reason' => 'Auto post',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'adjustment_type' => 'increase', 'quantity' => 12, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $res->assertJsonPath('data.status', 'posted');

        $balance = StockBalance::query()->where('product_id', $p->id)->where('warehouse_id', $wh->id)->firstOrFail();
        $this->assertSame(12.0, (float) $balance->quantity_on_hand);
        $this->assertDatabaseHas('stock_movements', [
            'source_type' => 'stock_adjustment',
            'source_id' => (int) $res->json('data.id'),
            'status' => 'posted',
        ], 'tenant');
    }

    public function test_manual_post_without_approval_allows_draft_to_post(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
            'approval_enabled' => false,
        ]);
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $res = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'adjustment_type' => 'increase', 'quantity' => 5, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $res->assertJsonPath('data.status', 'draft');

        $this->patchJson('/api/inventory/stock-adjustments/'.$res->json('data.id').'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');
    }

    public function test_approval_with_auto_post_posts_after_approval_without_bypassing_draft(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
            'approval_enabled' => true,
        ]);
        $this->seedInventoryMappings();

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $res = $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'adjustment_type' => 'increase', 'quantity' => 7, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $res->assertJsonPath('data.status', 'draft');

        $this->patchJson('/api/inventory/stock-adjustments/'.$res->json('data.id').'/approve', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'viewer');
        $this->postJson('/api/inventory/stock-adjustments', [
            'adjustment_date' => '2026-01-10',
            'lines' => [['product_id' => 1, 'warehouse_id' => 1, 'adjustment_type' => 'increase', 'quantity' => 1, 'unit_cost' => 1000]],
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
