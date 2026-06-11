<?php

namespace Tests\Feature\MasterData;

use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Warehouse;

class ProductTest extends MasterDataTestCase
{
    public function test_create_goods_with_unit_create_service_and_rules_stock_item(): void
    {
        $ctx = $this->setUpTenant();

        $unit = $this->postJson('/api/master-data/units', [
            'code' => 'PCS',
            'name' => 'Pieces',
            'precision' => 0,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $goods = $this->postJson('/api/master-data/products', [
            'product_name' => 'Product A',
            'product_type' => 'goods',
            'is_stock_item' => true,
            'unit_id' => $unit['id'],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/master-data/products', [
            'product_name' => 'Stock Without Unit',
            'is_stock_item' => true,
        ], $ctx['headers'])->assertStatus(422);

        $this->postJson('/api/master-data/products', [
            'product_name' => 'Service Bad',
            'product_type' => 'service',
            'is_stock_item' => true,
            'unit_id' => $unit['id'],
        ], $ctx['headers'])->assertStatus(422);

        $service = $this->postJson('/api/master-data/products', [
            'product_name' => 'Service A',
            'product_type' => 'service',
            'is_stock_item' => false,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/master-data/products/'.$goods['id'], [
            'description' => 'Updated',
        ], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/master-data/products/'.$service['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_product_list_includes_aggregated_current_stock_quantity(): void
    {
        $ctx = $this->setUpTenant();

        $unit = $this->postJson('/api/master-data/units', [
            'code' => 'CTN',
            'name' => 'Carton',
            'precision' => 0,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $product = $this->postJson('/api/master-data/products', [
            'product_code' => 'PRD-NDS-008',
            'product_name' => 'Air Mineral Karton',
            'product_type' => 'goods',
            'is_stock_item' => true,
            'unit_id' => $unit['id'],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $warehouseA = Warehouse::query()->create(['code' => 'WH-A', 'name' => 'Warehouse A', 'is_active' => true]);
        $warehouseB = Warehouse::query()->create(['code' => 'WH-B', 'name' => 'Warehouse B', 'is_active' => true]);

        StockBalance::query()->create([
            'product_id' => $product['id'],
            'warehouse_id' => $warehouseA->id,
            'quantity_on_hand' => 100,
            'quantity_available' => 100,
            'total_value' => 100000,
        ]);
        StockBalance::query()->create([
            'product_id' => $product['id'],
            'warehouse_id' => $warehouseB->id,
            'quantity_on_hand' => 65,
            'quantity_available' => 65,
            'total_value' => 65000,
        ]);

        $this->getJson('/api/master-data/products?page=1&per_page=10', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.data.0.product_code', 'PRD-NDS-008')
            ->assertJsonPath('data.data.0.current_quantity', 165)
            ->assertJsonPath('data.data.0.stock_quantity', 165)
            ->assertJsonPath('data.data.0.quantity_on_hand', 165)
            ->assertJsonPath('data.data.0.quantity_available', 165);
    }

    public function test_sales_account_must_be_active_revenue_account(): void
    {
        $ctx = $this->setUpTenant();
        $revenue = ChartOfAccount::query()->create([
            'account_code' => '4100',
            'account_name' => 'Sales Revenue',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);
        $asset = ChartOfAccount::query()->create([
            'account_code' => '1100',
            'account_name' => 'Accounts Receivable',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $inactiveRevenue = ChartOfAccount::query()->create([
            'account_code' => '4199',
            'account_name' => 'Inactive Revenue',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_active' => false,
        ]);

        $product = $this->postJson('/api/master-data/products', [
            'product_name' => 'Revenue Product',
            'product_type' => 'service',
            'sales_account_id' => $revenue->id,
        ], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_account_id', $revenue->id)
            ->json('data');

        $this->postJson('/api/master-data/products', [
            'product_name' => 'Bad Product',
            'product_type' => 'service',
            'sales_account_id' => $asset->id,
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'sales_account_id' => $inactiveRevenue->id,
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_purchase_inventory_and_cogs_accounts_must_match_account_types(): void
    {
        $ctx = $this->setUpTenant();
        $expense = ChartOfAccount::query()->create([
            'account_code' => '5100',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $asset = ChartOfAccount::query()->create([
            'account_code' => '1130',
            'account_name' => 'Inventory',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $liability = ChartOfAccount::query()->create([
            'account_code' => '2100',
            'account_name' => 'Payable',
            'account_type' => 'liability',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        $product = $this->postJson('/api/master-data/products', [
            'product_name' => 'Accounting Product',
            'product_type' => 'goods',
            'purchase_account_id' => $expense->id,
            'inventory_account_id' => $asset->id,
            'cogs_account_id' => $expense->id,
        ], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.purchase_account_id', $expense->id)
            ->assertJsonPath('data.inventory_account_id', $asset->id)
            ->assertJsonPath('data.cogs_account_id', $expense->id)
            ->json('data');

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'purchase_account_id' => $asset->id,
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'inventory_account_id' => $liability->id,
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'cogs_account_id' => $asset->id,
        ], $ctx['headers'])->assertStatus(422);
    }
}
