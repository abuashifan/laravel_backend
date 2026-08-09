<?php

namespace App\Modules\MasterData\Services;

use App\Modules\Inventory\Models\StockBalance;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Services\Concerns\ParsesBooleanFilters;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Exceptions\ApiException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;

class ProductService
{
    use AppliesListQuery;
    use ParsesBooleanFilters;

    protected array $listSearchable = ['product_code', 'product_name'];

    protected array $listSearchableRelations = [];

    protected string $listDateColumn = '';

    protected string $listStatusColumn = 'is_active';

    protected array $listDefaultSort = ['product_name' => 'asc'];

    protected array $listSortable = ['product_code', 'product_name', 'product_type', 'is_active'];

    /**
     * Agregat stok tetap dihitung SETELAH paginasi -- kini hanya untuk baris
     * yang benar-benar dikirim, bukan seluruh tabel produk seperti sebelumnya.
     * Hasilnya identik karena `attachStockQuantities()` menjumlahkan per
     * product_id, tidak bergantung pada produk lain di halaman yang sama.
     *
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,Product>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = Product::query()->with(['category', 'unit']);

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', $this->toBool($filters['is_active']));
        }

        if (! empty($filters['product_type'])) {
            $query->where('product_type', (string) $filters['product_type']);
        }

        // Filter kategori dari sidebar daftar produk. `category_id` diterima
        // sebagai alias karena modul laporan persediaan memakai nama itu.
        $categoryId = $filters['product_category_id'] ?? $filters['category_id'] ?? null;
        if (! empty($categoryId)) {
            $query->where('product_category_id', (int) $categoryId);
        }

        $result = $this->applyListQuery($query, $filters);

        if ($result instanceof ConcretePaginator) {
            return $result->setCollection($this->attachStockQuantities($result->getCollection()));
        }

        return $this->attachStockQuantities($result);
    }

    public function create(array $data): Product
    {
        $this->validateBusinessRules($data);

        if (! empty($data['product_code']) && Product::query()->where('product_code', (string) $data['product_code'])->exists()) {
            throw ApiException::make('DUPLICATE_PRODUCT_CODE', 'Product code is already in use.', 422, [
                'product_code' => ['Product Code is already in use.'],
            ]);
        }

        $this->validateRelations($data);

        return Product::query()->create($data);
    }

    public function update(Product $product, array $data): Product
    {
        $merged = array_merge($product->toArray(), $data);
        $this->validateBusinessRules($merged);

        if (! empty($data['product_code']) && $data['product_code'] !== $product->product_code) {
            if (Product::query()->where('product_code', (string) $data['product_code'])->exists()) {
                throw ApiException::make('DUPLICATE_PRODUCT_CODE', 'Product code is already in use.', 422, [
                    'product_code' => ['Product Code is already in use.'],
                ]);
            }
        }

        $this->validateRelations($data);

        // `UpdateProductRequest` menerima `is_active` dan `fill()` meneruskannya,
        // jadi tanpa penjaga ini `PATCH /products/{id}` dengan
        // `{"is_active": false}` melewati larangan di `deactivate()` sepenuhnya.
        // Hanya diperiksa saat benar-benar mematikan produk yang sedang aktif --
        // menyimpan form produk yang memang sudah nonaktif tidak ikut ditolak.
        if (array_key_exists('is_active', $data)
            && ! $this->toBool($data['is_active'])
            && $product->is_active) {
            $this->assertNoRemainingStock($product);
        }

        $product->fill($data);
        $product->save();

        return $product->refresh();
    }

    public function deactivate(Product $product): Product
    {
        $this->assertNoRemainingStock($product);

        $product->is_active = false;
        $product->save();

        return $product->refresh();
    }

    /**
     * Total stok on-hand seluruh gudang untuk satu produk.
     *
     * Dibulatkan ke presisi stok lebih dulu -- tanpa itu sisa pecahan float
     * (mis. 1e-15 dari penyesuaian berulang) menahan penonaktifan produk yang
     * stoknya sebenarnya sudah nol. Presisinya sengaja sama dengan
     * `attachStockQuantities()`.
     *
     * Dibaca langsung dari StockBalance, bukan dari atribut
     * `current_quantity`: atribut itu hanya dipasang di `list()`, sedangkan
     * di sini produknya diambil per-id.
     */
    private function stockOnHandFor(Product $product): float
    {
        return round(
            (float) StockBalance::query()
                ->where('product_id', $product->id)
                ->sum('quantity_on_hand'),
            (int) config('inventory.stock_precision', 4),
        );
    }

    /**
     * Produk berstok tidak boleh dinonaktifkan.
     *
     * Menonaktifkannya membuat stoknya terdampar: saldonya tetap terhitung di
     * Neraca, tapi produknya hilang dari seluruh picker (`produkApi.search`
     * selalu mengirim `is_active: true`) -- termasuk Penyesuaian Stok, alat
     * yang justru dipakai untuk menghapusbukukannya. Selama produknya masih
     * aktif semua jalur itu berfungsi, jadi penjaga ini hanya memaksa
     * urutannya benar: habiskan stok dulu, baru nonaktifkan.
     *
     * `!= 0`, bukan `> 0` -- stok negatif adalah keadaan galat, dan
     * menonaktifkan produknya justru menyembunyikannya.
     */
    private function assertNoRemainingStock(Product $product): void
    {
        $quantity = $this->stockOnHandFor($product);

        if ($quantity == 0.0) {
            return;
        }

        throw ApiException::make(
            'PRODUCT_HAS_STOCK',
            'Cannot deactivate product that still has stock.',
            422,
            [],
            ['quantity_on_hand' => $quantity],
        );
    }

    public function activate(Product $product): Product
    {
        $product->is_active = true;
        $product->save();

        return $product->refresh();
    }

    private function validateBusinessRules(array $data): void
    {
        $isStockItem = (bool) ($data['is_stock_item'] ?? false);
        $unitId = $data['unit_id'] ?? null;

        if ($isStockItem && empty($unitId)) {
            throw ApiException::make('UNIT_REQUIRED_FOR_STOCK_ITEM', 'unit_id is required for stock items.', 422);
        }

        $productType = (string) ($data['product_type'] ?? 'goods');
        if ($productType === 'service' && $isStockItem) {
            throw ApiException::make('SERVICE_CANNOT_BE_STOCK_ITEM', 'Service product cannot be stock item.', 422);
        }
    }

    private function validateRelations(array $data): void
    {
        if (array_key_exists('product_category_id', $data) && $data['product_category_id'] !== null) {
            if (! ProductCategory::query()->whereKey((int) $data['product_category_id'])->exists()) {
                throw ApiException::make('PRODUCT_CATEGORY_NOT_FOUND', 'Product category not found.', 422);
            }
        }

        if (array_key_exists('unit_id', $data) && $data['unit_id'] !== null) {
            if (! Unit::query()->whereKey((int) $data['unit_id'])->exists()) {
                throw ApiException::make('UNIT_NOT_FOUND', 'Unit not found.', 422);
            }
        }

        foreach (['sales_account_id', 'sales_discount_account_id', 'sales_return_account_id', 'purchase_return_account_id', 'inventory_account_id', 'inventory_interim_account_id', 'cogs_account_id'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $query = ChartOfAccount::query()->whereKey((int) $data[$key])->where('is_active', true);
                $type = match ($key) {
                    'sales_account_id', 'sales_discount_account_id' => 'revenue',
                    'sales_return_account_id', 'purchase_return_account_id' => ['revenue', 'expense'],
                    'cogs_account_id' => 'expense',
                    'inventory_account_id' => 'asset',
                    'inventory_interim_account_id' => 'liability',
                    default => null,
                };
                if ($type !== null) {
                    is_array($type) ? $query->whereIn('account_type', $type) : $query->where('account_type', $type);
                }
                if (! $query->exists()) {
                    throw ApiException::make('ACCOUNT_NOT_FOUND', $key.' not found.', 422);
                }
            }
        }
    }

    private function attachStockQuantities($products)
    {
        $productIds = $products->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($productIds === []) {
            return $products;
        }

        $balances = StockBalance::query()
            ->selectRaw('product_id, SUM(quantity_on_hand) as quantity_on_hand, SUM(quantity_reserved) as quantity_reserved, SUM(quantity_available) as quantity_available, SUM(total_value) as total_value')
            ->whereIn('product_id', $productIds)
            ->groupBy('product_id')
            ->get()
            ->keyBy(fn ($balance) => (int) $balance->product_id);

        return $products->map(function (Product $product) use ($balances): Product {
            $balance = $balances->get((int) $product->id);
            $quantityOnHand = round((float) ($balance?->quantity_on_hand ?? 0), (int) config('inventory.stock_precision', 4));
            $quantityReserved = round((float) ($balance?->quantity_reserved ?? 0), (int) config('inventory.stock_precision', 4));
            $quantityAvailable = round((float) ($balance?->quantity_available ?? 0), (int) config('inventory.stock_precision', 4));
            $totalValue = round((float) ($balance?->total_value ?? 0), (int) config('inventory.amount_precision', 2));

            $product->setAttribute('current_quantity', $quantityOnHand);
            $product->setAttribute('stock_quantity', $quantityOnHand);
            $product->setAttribute('quantity_on_hand', $quantityOnHand);
            $product->setAttribute('quantity_reserved', $quantityReserved);
            $product->setAttribute('quantity_available', $quantityAvailable);
            $product->setAttribute('stock_total_value', $totalValue);

            return $product;
        });
    }
}
