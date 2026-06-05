<?php

namespace App\Services\Inventory\Reports;

use App\Models\Tenant\StockBalance;

class StockBalanceReportService
{
    public function report(array $filters = []): array
    {
        $q = StockBalance::query()->with(['product', 'warehouse']);

        if (! empty($filters['product_id'])) $q->where('product_id', (int) $filters['product_id']);
        if (! empty($filters['warehouse_id'])) $q->where('warehouse_id', (int) $filters['warehouse_id']);
        if (! empty($filters['category_id'])) {
            $catId = (int) $filters['category_id'];
            $q->whereHas('product', fn ($p) => $p->where('product_category_id', $catId));
        }

        $includeZero = (bool) ($filters['include_zero'] ?? false);
        $includeNegative = (bool) ($filters['include_negative'] ?? false);
        if (! $includeZero) $q->where('quantity_on_hand', '!=', 0);
        if (! $includeNegative) $q->where('quantity_on_hand', '>=', 0);

        $rows = $q->orderBy('product_id')->orderBy('warehouse_id')->get()->map(function (StockBalance $b) {
            return [
                'product' => [
                    'id' => (int) $b->product_id,
                    'code' => $b->product?->product_code,
                    'name' => $b->product?->product_name,
                    'category_id' => $b->product?->product_category_id,
                ],
                'warehouse' => [
                    'id' => (int) $b->warehouse_id,
                    'code' => $b->warehouse?->code,
                    'name' => $b->warehouse?->name,
                ],
                'quantity_on_hand' => (float) $b->quantity_on_hand,
                'quantity_reserved' => (float) $b->quantity_reserved,
                'quantity_available' => (float) $b->quantity_available,
                'average_cost' => (float) $b->average_cost,
                'total_value' => (float) $b->total_value,
            ];
        })->values()->all();

        $totQty = array_sum(array_map(fn ($r) => (float) $r['quantity_on_hand'], $rows));
        $totVal = array_sum(array_map(fn ($r) => (float) $r['total_value'], $rows));

        return [
            'filters' => $filters,
            'rows' => $rows,
            'totals' => [
                'total_quantity_on_hand' => $totQty,
                'total_value' => round($totVal, (int) config('inventory.amount_precision', 2)),
            ],
        ];
    }

    public function byProduct(int $productId): array
    {
        return $this->report(['product_id' => $productId]);
    }

    public function byWarehouse(int $warehouseId): array
    {
        return $this->report(['warehouse_id' => $warehouseId]);
    }
}

