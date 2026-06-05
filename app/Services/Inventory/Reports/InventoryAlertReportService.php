<?php

namespace App\Services\Inventory\Reports;

use App\Models\Tenant\StockBalance;

class InventoryAlertReportService
{
    public function lowStock(array $filters = []): array
    {
        $threshold = isset($filters['threshold']) ? (float) $filters['threshold'] : 1.0;

        $q = StockBalance::query()->with(['product', 'warehouse'])
            ->where('quantity_on_hand', '>', 0)
            ->where('quantity_on_hand', '<', $threshold);

        $this->applyCommonFilters($q, $filters);

        return [
            'filters' => array_merge($filters, ['threshold' => $threshold]),
            'rows' => $q->orderBy('quantity_on_hand')->get()->map(fn ($b) => [
                'product_id' => (int) $b->product_id,
                'product_code' => $b->product?->product_code,
                'product_name' => $b->product?->product_name,
                'warehouse_id' => (int) $b->warehouse_id,
                'warehouse_code' => $b->warehouse?->code,
                'warehouse_name' => $b->warehouse?->name,
                'quantity_on_hand' => (float) $b->quantity_on_hand,
            ])->values()->all(),
        ];
    }

    public function negativeStock(array $filters = []): array
    {
        $q = StockBalance::query()->with(['product', 'warehouse'])
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
            ])->values()->all(),
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

