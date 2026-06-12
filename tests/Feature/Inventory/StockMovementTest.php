<?php

namespace Tests\Feature\Inventory;

use App\Models\CompanyAccountingSetting;
use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\StockMovement;
use App\Models\Tenant\Unit;
use App\Models\Tenant\Warehouse;
use App\Support\AccountMapping\AccountMappingKey;
use App\Services\Accounting\FiscalYearService;
use Tests\Feature\Journal\JournalTestCase;

class StockMovementTest extends JournalTestCase
{
    public function test_unauthenticated_rejected(): void
    {
        auth()->logout();
        $this->getJson('/api/inventory/stock-movements')->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'warehouse');
        $this->getJson('/api/inventory/stock-movements')->assertStatus(422);
    }

    public function test_can_create_draft_stock_movement(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $res = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers']);

        $res->assertStatus(201);
        $res->assertJsonPath('data.status', 'draft');
    }

    public function test_can_post_opening_stock_and_adjustments_and_void_creates_reversal(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        // setup mappings needed for journals
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

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        // opening stock
        $m = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 10, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $mid = (int) $m->json('data.id');

        $this->patchJson('/api/inventory/stock-movements/'.$mid.'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        // adjustment in
        $a = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-11',
            'movement_type' => 'adjustment_in',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $aid = (int) $a->json('data.id');
        $this->patchJson('/api/inventory/stock-movements/'.$aid.'/post', [], $ctx['headers'])->assertStatus(200);

        // adjustment out
        $b = $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-12',
            'movement_type' => 'adjustment_out',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $bid = (int) $b->json('data.id');
        $this->patchJson('/api/inventory/stock-movements/'.$bid.'/post', [], $ctx['headers'])->assertStatus(200);

        // void posted -> should create reversal movement
        $this->patchJson('/api/inventory/stock-movements/'.$bid.'/void', ['reason' => 'mistake'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');

        $orig = StockMovement::query()->findOrFail($bid);
        $this->assertNotNull($orig->reversed_by_id);
    }

    public function test_cannot_double_post_same_source(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'SKU1', 'product_name' => 'Item', 'product_type' => 'goods', 'unit_id' => $unit->id, 'is_stock_item' => true, 'is_active' => true]);

        $payload = [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'source_type' => 'goods_receipt',
            'source_id' => 1,
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ];

        $this->postJson('/api/inventory/stock-movements', $payload, $ctx['headers'])->assertStatus(201);
        $res2 = $this->postJson('/api/inventory/stock-movements', $payload, $ctx['headers']);
        $res2->assertStatus(422);
        $res2->assertJsonPath('code', 'DUPLICATE_SOURCE_MOVEMENT');
    }

    public function test_stock_movement_rejects_non_stock_product(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $unit = Unit::query()->create(['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0, 'is_active' => true]);
        $wh = Warehouse::query()->create(['code' => 'WH1', 'name' => 'Main', 'is_default' => true, 'is_active' => true]);
        $p = Product::query()->create(['product_code' => 'NS1', 'product_name' => 'Service', 'product_type' => 'service', 'unit_id' => $unit->id, 'is_stock_item' => false, 'is_active' => true]);

        $this->postJson('/api/inventory/stock-movements', [
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_NOT_STOCKABLE');
    }

    public function test_period_lock_blocks_posting(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

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
            'movement_date' => '2026-01-10',
            'movement_type' => 'opening_stock',
            'lines' => [
                ['product_id' => $p->id, 'warehouse_id' => $wh->id, 'unit_id' => $unit->id, 'quantity' => 1, 'unit_cost' => 1000],
            ],
        ], $ctx['headers'])->assertStatus(201);

        $mid = (int) $m->json('data.id');
        $res = $this->patchJson('/api/inventory/stock-movements/'.$mid.'/post', [], $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'TRANSACTION_PERIOD_LOCKED');
    }
}
