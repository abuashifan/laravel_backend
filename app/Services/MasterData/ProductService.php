<?php

namespace App\Services\MasterData;

use App\Exceptions\ApiException;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Product;
use App\Models\Tenant\ProductCategory;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Unit;

class ProductService
{
    public function list(array $filters = [])
    {
        $query = Product::query();

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (! empty($filters['product_type'])) {
            $query->where('product_type', (string) $filters['product_type']);
        }

        $products = $query->orderBy('product_name')->get();

        return $this->attachStockQuantities($products);
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

        $product->fill($data);
        $product->save();

        return $product->refresh();
    }

    public function deactivate(Product $product): Product
    {
        $product->is_active = false;
        $product->save();

        return $product->refresh();
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

        foreach (['sales_account_id', 'purchase_account_id', 'inventory_account_id', 'cogs_account_id'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null) {
                $query = ChartOfAccount::query()->whereKey((int) $data[$key])->where('is_active', true);
                if ($key === 'sales_account_id') {
                    $query->where('account_type', 'revenue');
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
