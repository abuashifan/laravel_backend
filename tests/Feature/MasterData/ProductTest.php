<?php

namespace Tests\Feature\MasterData;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Warehouse;

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

    public function test_inventory_and_cogs_accounts_must_match_account_types(): void
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
            'inventory_account_id' => $asset->id,
            'cogs_account_id' => $expense->id,
        ], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.inventory_account_id', $asset->id)
            ->assertJsonPath('data.cogs_account_id', $expense->id)
            ->json('data');

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'inventory_account_id' => $liability->id,
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'cogs_account_id' => $asset->id,
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_purchase_account_id_is_no_longer_accepted_on_product(): void
    {
        $ctx = $this->setUpTenant();
        $expense = ChartOfAccount::query()->create([
            'account_code' => '5100',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);

        $product = $this->postJson('/api/master-data/products', [
            'product_name' => 'No Purchase Account Product',
            'product_type' => 'goods',
            'purchase_account_id' => $expense->id,
        ], $ctx['headers'])
            ->assertStatus(201)
            ->json('data');

        $this->assertArrayNotHasKey('purchase_account_id', $product);
    }

    public function test_extended_account_fields_must_match_account_types(): void
    {
        $ctx = $this->setUpTenant();
        $revenue = ChartOfAccount::query()->create([
            'account_code' => '4100',
            'account_name' => 'Revenue',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);
        $expense = ChartOfAccount::query()->create([
            'account_code' => '5100',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $liability = ChartOfAccount::query()->create([
            'account_code' => '2150',
            'account_name' => 'Interim',
            'account_type' => 'liability',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        $product = $this->postJson('/api/master-data/products', [
            'product_name' => 'Extended Accounts Product',
            'product_type' => 'goods',
            'sales_discount_account_id' => $revenue->id,
            'sales_return_account_id' => $revenue->id,
            'purchase_return_account_id' => $expense->id,
            'inventory_interim_account_id' => $liability->id,
        ], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_discount_account_id', $revenue->id)
            ->assertJsonPath('data.sales_return_account_id', $revenue->id)
            ->assertJsonPath('data.purchase_return_account_id', $expense->id)
            ->assertJsonPath('data.inventory_interim_account_id', $liability->id)
            ->json('data');

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'sales_discount_account_id' => $expense->id,
        ], $ctx['headers'])->assertStatus(422);

        // sales_return_account_id and purchase_return_account_id accept both revenue and
        // expense (contra accounts are modeled either way depending on chart-of-accounts
        // design), so only a genuinely unrelated type (liability) should be rejected.
        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'sales_return_account_id' => $liability->id,
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'purchase_return_account_id' => $liability->id,
        ], $ctx['headers'])->assertStatus(422);

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'inventory_interim_account_id' => $revenue->id,
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_product_list_can_be_filtered_by_category(): void
    {
        $ctx = $this->setUpTenant();

        $minuman = $this->postJson('/api/master-data/product-categories', [
            'name' => 'Minuman',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $makanan = $this->postJson('/api/master-data/product-categories', [
            'name' => 'Makanan',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        foreach ([['Kopi', $minuman['id']], ['Teh', $minuman['id']], ['Roti', $makanan['id']]] as [$name, $categoryId]) {
            $this->postJson('/api/master-data/products', [
                'product_name' => $name,
                'product_type' => 'goods',
                'product_category_id' => $categoryId,
            ], $ctx['headers'])->assertStatus(201);
        }

        // Tanpa kategori sama sekali -- harus tetap ikut terhitung saat filter kosong,
        // dan tidak boleh muncul saat kategori dipilih.
        $this->postJson('/api/master-data/products', [
            'product_name' => 'Tanpa Kategori',
            'product_type' => 'service',
        ], $ctx['headers'])->assertStatus(201);

        $this->getJson('/api/master-data/products?page=1&per_page=25', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.total', 4);

        $filtered = $this->getJson(
            '/api/master-data/products?page=1&per_page=25&product_category_id='.$minuman['id'],
            $ctx['headers'],
        )->assertStatus(200)->assertJsonPath('data.total', 2)->json('data.data');

        $this->assertSame(['Kopi', 'Teh'], collect($filtered)->pluck('product_name')->sort()->values()->all());

        // Alias `category_id` dipakai modul laporan persediaan.
        $this->getJson(
            '/api/master-data/products?page=1&per_page=25&category_id='.$makanan['id'],
            $ctx['headers'],
        )->assertStatus(200)->assertJsonPath('data.total', 1)->assertJsonPath('data.data.0.product_name', 'Roti');

        // Kategori digabung dengan pencarian, bukan saling menimpa.
        $this->getJson(
            '/api/master-data/products?page=1&per_page=25&search=Kopi&product_category_id='.$makanan['id'],
            $ctx['headers'],
        )->assertStatus(200)->assertJsonPath('data.total', 0);
    }

    /**
     * Buat satu produk stok + saldo di satu gudang.
     *
     * @return array{ctx: array<string,mixed>, product: array<string,mixed>}
     */
    private function productWithStock(float $quantityOnHand): array
    {
        $ctx = $this->setUpTenant();

        $unit = $this->postJson('/api/master-data/units', [
            'code' => 'PCS', 'name' => 'Pieces', 'precision' => 0,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $product = $this->postJson('/api/master-data/products', [
            'product_code' => 'PRD-STK-001',
            'product_name' => 'Semen 50kg',
            'product_type' => 'goods',
            'is_stock_item' => true,
            'unit_id' => $unit['id'],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $warehouse = Warehouse::query()->create(['code' => 'WH-A', 'name' => 'Gudang A', 'is_active' => true]);

        StockBalance::query()->create([
            'product_id' => $product['id'],
            'warehouse_id' => $warehouse->id,
            'quantity_on_hand' => $quantityOnHand,
            'quantity_available' => $quantityOnHand,
            'total_value' => $quantityOnHand * 1000,
        ]);

        return ['ctx' => $ctx, 'product' => $product];
    }

    public function test_cannot_deactivate_product_with_remaining_stock(): void
    {
        ['ctx' => $ctx, 'product' => $product] = $this->productWithStock(100);

        $this->patchJson('/api/master-data/products/'.$product['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_HAS_STOCK')
            ->assertJsonPath('meta.quantity_on_hand', 100);

        // Status respons saja tidak cukup -- produknya harus benar-benar
        // masih aktif.
        $this->getJson('/api/master-data/products/'.$product['id'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_can_deactivate_product_after_stock_reaches_zero(): void
    {
        ['ctx' => $ctx, 'product' => $product] = $this->productWithStock(0);

        $this->patchJson('/api/master-data/products/'.$product['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(200);

        $this->getJson('/api/master-data/products/'.$product['id'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    /** Stok negatif adalah keadaan galat -- membedakan `!= 0` dari `> 0`. */
    public function test_negative_stock_also_blocks_deactivation(): void
    {
        ['ctx' => $ctx, 'product' => $product] = $this->productWithStock(-5);

        $this->patchJson('/api/master-data/products/'.$product['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_HAS_STOCK');
    }

    /**
     * `UpdateProductRequest` menerima `is_active` dan `update()` memakai
     * `fill()`, jadi tanpa penjaga kedua larangan di atas sepele dilewati.
     */
    public function test_update_endpoint_cannot_bypass_stock_guard(): void
    {
        ['ctx' => $ctx, 'product' => $product] = $this->productWithStock(100);

        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'is_active' => false,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PRODUCT_HAS_STOCK');

        $this->getJson('/api/master-data/products/'.$product['id'], $ctx['headers'])
            ->assertJsonPath('data.is_active', true);

        // Perubahan lain pada produk berstok tetap boleh -- penjaganya hanya
        // menyoal mematikan status, bukan menyunting.
        $this->patchJson('/api/master-data/products/'.$product['id'], [
            'product_name' => 'Semen 50kg (Revisi)',
        ], $ctx['headers'])->assertStatus(200);
    }

    /** Produk jasa tidak punya baris stock_balances sama sekali. */
    public function test_product_without_stock_balance_rows_can_be_deactivated(): void
    {
        $ctx = $this->setUpTenant();

        $product = $this->postJson('/api/master-data/products', [
            'product_code' => 'SRV-001',
            'product_name' => 'Jasa Pasang',
            'product_type' => 'service',
            'is_stock_item' => false,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/master-data/products/'.$product['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(200);

        $this->getJson('/api/master-data/products/'.$product['id'], $ctx['headers'])
            ->assertJsonPath('data.is_active', false);
    }
}
