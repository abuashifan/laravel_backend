<?php

namespace App\Services\Inventory;

use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovementLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class InventoryValuationService
{
    public function currentValuation(array $filters = []): array
    {
        $query = StockBalance::query()->with(['product', 'warehouse']);
        $this->applyBalanceFilters($query, $filters);

        $rows = $query->get()->map(fn (StockBalance $b) => $this->rowFromBalance($b))->values()->all();

        return [
            'as_of_date' => null,
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    public function valuationAsOf(?string $date = null, array $filters = []): array
    {
        $asOf = $date ?: ($filters['as_of_date'] ?? null);
        $asOf = $asOf ? (string) $asOf : null;

        $linesQ = StockMovementLine::query()
            ->select('stock_movement_lines.*')
            ->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lines.stock_movement_id')
            ->where('stock_movements.status', 'posted')
            ->orderBy('stock_movements.movement_date')
            ->orderBy('stock_movements.movement_number')
            ->orderBy('stock_movement_lines.sort_order')
            ->orderBy('stock_movement_lines.id');

        if ($asOf) {
            $linesQ->whereDate('stock_movements.movement_date', '<=', $asOf);
        }

        if (! empty($filters['product_id'])) $linesQ->where('stock_movement_lines.product_id', (int) $filters['product_id']);
        if (! empty($filters['warehouse_id'])) $linesQ->where('stock_movement_lines.warehouse_id', (int) $filters['warehouse_id']);

        if (! empty($filters['category_id'])) {
            $catId = (int) $filters['category_id'];
            $linesQ->join('products', 'products.id', '=', 'stock_movement_lines.product_id')
                ->where('products.category_id', $catId);
        }

        /** @var Collection<string, array{product_id:int,warehouse_id:int,quantity_on_hand:float,average_cost:float,total_value:float}> $state */
        $state = collect();

        $linesQ->with('stockMovement')->chunk(500, function ($lines) use (&$state) {
            foreach ($lines as $ln) {
                $key = (int) $ln->product_id.'|'.(int) $ln->warehouse_id;
                $cur = $state->get($key, [
                    'product_id' => (int) $ln->product_id,
                    'warehouse_id' => (int) $ln->warehouse_id,
                    'quantity_on_hand' => 0.0,
                    'average_cost' => 0.0,
                    'total_value' => 0.0,
                ]);

                $qty = (float) $ln->quantity;
                $direction = (string) $ln->direction;

                if ($direction === 'in') {
                    $unitCost = (float) ($ln->unit_cost ?? 0);
                    $incomingValue = round($qty * $unitCost, (int) config('inventory.amount_precision', 2));
                    $cur['quantity_on_hand'] = round($cur['quantity_on_hand'] + $qty, (int) config('inventory.stock_precision', 4));
                    $cur['total_value'] = round($cur['total_value'] + $incomingValue, (int) config('inventory.amount_precision', 2));
                } else {
                    $outValue = round($qty * (float) $cur['average_cost'], (int) config('inventory.amount_precision', 2));
                    $cur['quantity_on_hand'] = round($cur['quantity_on_hand'] - $qty, (int) config('inventory.stock_precision', 4));
                    $cur['total_value'] = round($cur['total_value'] - $outValue, (int) config('inventory.amount_precision', 2));
                }

                $cur['average_cost'] = $cur['quantity_on_hand'] == 0.0
                    ? 0.0
                    : round($cur['total_value'] / $cur['quantity_on_hand'], (int) config('inventory.cost_precision', 6));

                $state->put($key, $cur);
            }
        });

        $productIds = $state->pluck('product_id')->unique()->values()->all();
        $whIds = $state->pluck('warehouse_id')->unique()->values()->all();

        $products = Product::query()->whereIn('id', $productIds)->get()->keyBy('id');
        $warehouses = \App\Models\Tenant\Warehouse::query()->whereIn('id', $whIds)->get()->keyBy('id');

        $includeZero = (bool) ($filters['include_zero'] ?? false);
        $includeNegative = (bool) ($filters['include_negative'] ?? false);

        $rows = $state->values()->filter(function (array $r) use ($includeZero, $includeNegative) {
            if (! $includeNegative && $r['quantity_on_hand'] < 0) return false;
            if (! $includeZero && abs((float) $r['quantity_on_hand']) < 1e-9) return false;
            return true;
        })->map(function (array $r) use ($products, $warehouses) {
            $p = $products->get($r['product_id']);
            $w = $warehouses->get($r['warehouse_id']);
            return [
                'product_id' => (int) $r['product_id'],
                'product_code' => $p?->product_code,
                'product_name' => $p?->product_name,
                'warehouse_id' => (int) $r['warehouse_id'],
                'warehouse_code' => $w?->code,
                'warehouse_name' => $w?->name,
                'quantity_on_hand' => (float) $r['quantity_on_hand'],
                'average_cost' => (float) $r['average_cost'],
                'total_value' => (float) $r['total_value'],
            ];
        })->values()->all();

        return [
            'as_of_date' => $asOf,
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    public function valuationByProduct(int $productId, array $filters = []): array
    {
        $filters['product_id'] = $productId;
        return $this->currentValuation($filters);
    }

    public function valuationByWarehouse(int $warehouseId, array $filters = []): array
    {
        $filters['warehouse_id'] = $warehouseId;
        return $this->currentValuation($filters);
    }

    private function applyBalanceFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['product_id'])) $query->where('product_id', (int) $filters['product_id']);
        if (! empty($filters['warehouse_id'])) $query->where('warehouse_id', (int) $filters['warehouse_id']);
        if (! empty($filters['category_id'])) {
            $catId = (int) $filters['category_id'];
            $query->whereHas('product', fn ($q) => $q->where('category_id', $catId));
        }

        $includeZero = (bool) ($filters['include_zero'] ?? false);
        $includeNegative = (bool) ($filters['include_negative'] ?? false);
        if (! $includeZero) {
            $query->where('quantity_on_hand', '!=', 0);
        }
        if (! $includeNegative) {
            $query->where('quantity_on_hand', '>=', 0);
        }
    }

    private function rowFromBalance(StockBalance $b): array
    {
        return [
            'product_id' => (int) $b->product_id,
            'product_code' => $b->product?->product_code,
            'product_name' => $b->product?->product_name,
            'category_id' => $b->product?->product_category_id,
            'warehouse_id' => (int) $b->warehouse_id,
            'warehouse_code' => $b->warehouse?->code,
            'warehouse_name' => $b->warehouse?->name,
            'quantity_on_hand' => (float) $b->quantity_on_hand,
            'average_cost' => (float) $b->average_cost,
            'total_value' => (float) $b->total_value,
            'is_negative' => (float) $b->quantity_on_hand < 0,
        ];
    }

    private function totals(array $rows): array
    {
        $qty = 0.0;
        $value = 0.0;
        foreach ($rows as $r) {
            $qty += (float) ($r['quantity_on_hand'] ?? 0);
            $value += (float) ($r['total_value'] ?? 0);
        }

        return [
            'total_quantity_on_hand' => $qty,
            'total_value' => round($value, (int) config('inventory.amount_precision', 2)),
        ];
    }
}
