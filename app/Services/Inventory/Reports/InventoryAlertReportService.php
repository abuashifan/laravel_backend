<?php

namespace App\Services\Inventory\Reports;

use App\Models\Tenant\StockBalance;
use App\Services\Inventory\InventoryConfigService;

class InventoryAlertReportService
{
    public function __construct(private readonly InventoryConfigService $configService)
    {
    }

    public function lowStock(array $filters = []): array
    {
        // Per-product min_stock takes priority; global threshold is a fallback
        // when a product has no min_stock set.
        $globalThreshold = isset($filters['threshold']) ? (float) $filters['threshold'] : null;

        $q = StockBalance::query()->with(['product.unit', 'warehouse']);

        $this->applyCommonFilters($q, $filters);

        $rows = $q->get()->filter(function ($b) use ($globalThreshold): bool {
            $minStock = $b->product?->min_stock;
            $threshold = $minStock !== null ? (float) $minStock : $globalThreshold;
            if ($threshold === null) {
                return false;
            }
            return (float) $b->quantity_on_hand < $threshold;
        })->sortBy('quantity_on_hand')->values();

        return [
            'filters' => array_merge($filters, ['threshold' => $globalThreshold]),
            'rows' => $rows->map(fn ($b) => [
                'product_id' => (int) $b->product_id,
                'product_code' => $b->product?->product_code,
                'product_name' => $b->product?->product_name,
                'warehouse_id' => (int) $b->warehouse_id,
                'warehouse_code' => $b->warehouse?->code,
                'warehouse_name' => $b->warehouse?->name,
                'quantity_on_hand' => (float) $b->quantity_on_hand,
                'min_stock' => $b->product?->min_stock !== null ? (float) $b->product->min_stock : null,
                'unit' => $b->product?->unit?->name ?? '',
            ])->values()->all(),
            'policy' => $this->policy(),
        ];
    }

    public function negativeStock(array $filters = []): array
    {
        $q = StockBalance::query()->with(['product.unit', 'warehouse'])
            ->where('quantity_on_hand', '<', 0);

        $this->applyCommonFilters($q, $filters);

        return [
            'filters' => $filters,
            'rows' => $q->orderBy('quantity_on_hand')->get()->map(fn ($b) => [
                'product_id' => (int) $b->product_id,
                'product_code' => $b->product?->product_code,
                'product_name' => $b->product?->product_name,
                'warehouse_id' => (int) $b->warehouse_id,
                'warehouse_code' => $b->warehouse?->code,
                'warehouse_name' => $b->warehouse?->name,
                'quantity_on_hand' => (float) $b->quantity_on_hand,
                'unit' => $b->product?->unit?->name ?? '',
            ])->values()->all(),
            'policy' => $this->policy(),
        ];
    }

    public function zeroStock(array $filters = []): array
    {
        $q = StockBalance::query()->with(['product', 'warehouse'])
            ->where('quantity_on_hand', '=', 0);

        $this->applyCommonFilters($q, $filters);

        return [
            'filters' => $filters,
            'rows' => $q->orderBy('product_id')->get()->map(fn ($b) => [
                'product_id' => (int) $b->product_id,
                'product_code' => $b->product?->product_code,
                'product_name' => $b->product?->product_name,
                'warehouse_id' => (int) $b->warehouse_id,
                'warehouse_code' => $b->warehouse?->code,
                'warehouse_name' => $b->warehouse?->name,
                'quantity_on_hand' => (float) $b->quantity_on_hand,
            ])->values()->all(),
            'policy' => $this->policy(),
        ];
    }

    private function policy(): array
    {
        return [
            'allow_negative_stock' => $this->configService->allowNegativeStock(),
            'stock_precision' => $this->configService->stockPrecision(),
            'cost_precision' => $this->configService->costPrecision(),
            'amount_precision' => $this->configService->amountPrecision(),
        ];
    }

    private function applyCommonFilters($q, array $filters): void
    {
        if (! empty($filters['product_id'])) $q->where('product_id', (int) $filters['product_id']);
        if (! empty($filters['warehouse_id'])) $q->where('warehouse_id', (int) $filters['warehouse_id']);
        if (! empty($filters['category_id'])) {
            $catId = (int) $filters['category_id'];
            $q->whereHas('product', fn ($p) => $p->where('product_category_id', $catId));
        }
    }
}

