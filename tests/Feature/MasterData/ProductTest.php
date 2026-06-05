<?php

namespace Tests\Feature\MasterData;

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
}
