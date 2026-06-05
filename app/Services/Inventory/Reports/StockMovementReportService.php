<?php

namespace App\Services\Inventory\Reports;

use App\Models\Tenant\StockMovementLine;

class StockMovementReportService
{
    public function report(array $filters = []): array
    {
        $q = StockMovementLine::query()
            ->select('stock_movement_lines.*', 'stock_movements.movement_number', 'stock_movements.movement_date', 'stock_movements.movement_type', 'stock_movements.source_type', 'stock_movements.source_number', 'stock_movements.status as movement_status')
            ->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lines.stock_movement_id')
            ->with(['product', 'warehouse']);

        $includeVoid = (bool) ($filters['include_void'] ?? false);
        if ($includeVoid) {
            $q->whereIn('stock_movements.status', ['posted', 'void']);
        } else {
            $q->where('stock_movements.status', 'posted');
        }

        if (! empty($filters['product_id'])) $q->where('stock_movement_lines.product_id', (int) $filters['product_id']);
        if (! empty($filters['warehouse_id'])) $q->where('stock_movement_lines.warehouse_id', (int) $filters['warehouse_id']);
        if (! empty($filters['category_id'])) {
            $catId = (int) $filters['category_id'];
            $q->join('products', 'products.id', '=', 'stock_movement_lines.product_id')
                ->where('products.product_category_id', $catId);
        }

        if (! empty($filters['start_date'])) $q->whereDate('stock_movements.movement_date', '>=', (string) $filters['start_date']);
        if (! empty($filters['end_date'])) $q->whereDate('stock_movements.movement_date', '<=', (string) $filters['end_date']);

        $q->orderBy('stock_movements.movement_date')->orderBy('stock_movements.movement_number')->orderBy('stock_movement_lines.sort_order')->orderBy('stock_movement_lines.id');

        $rows = $q->get()->map(function ($ln) {
            $dir = (string) $ln->direction;
            return [
                'movement_date' => optional($ln->movement_date)->toDateString() ?: (string) $ln->movement_date,
                'movement_number' => (string) $ln->movement_number,
                'movement_type' => (string) $ln->movement_type,
                'movement_status' => (string) $ln->movement_status,
                'source_type' => $ln->source_type,
                'source_number' => $ln->source_number,
                'product' => [
                    'id' => (int) $ln->product_id,
                    'code' => $ln->product?->product_code,
                    'name' => $ln->product?->product_name,
                ],
                'warehouse' => [
                    'id' => (int) $ln->warehouse_id,
                    'code' => $ln->warehouse?->code,
                    'name' => $ln->warehouse?->name,
                ],
                'quantity_in' => $dir === 'in' ? (float) $ln->quantity : 0.0,
                'quantity_out' => $dir === 'out' ? (float) $ln->quantity : 0.0,
                'unit_cost' => (float) ($ln->unit_cost ?? 0),
                'total_cost' => (float) ($ln->total_cost ?? 0),
            ];
        })->values()->all();

        return [
            'filters' => $filters,
            'rows' => $rows,
            'summary' => $this->movementSummary($filters),
        ];
    }

    public function movementSummary(array $filters = []): array
    {
        $q = StockMovementLine::query()
            ->selectRaw("sum(case when stock_movement_lines.direction='in' then stock_movement_lines.quantity else 0 end) as qty_in")
            ->selectRaw("sum(case when stock_movement_lines.direction='out' then stock_movement_lines.quantity else 0 end) as qty_out")
            ->selectRaw("sum(case when stock_movement_lines.direction='in' then stock_movement_lines.total_cost else 0 end) as value_in")
            ->selectRaw("sum(case when stock_movement_lines.direction='out' then stock_movement_lines.total_cost else 0 end) as value_out")
            ->join('stock_movements', 'stock_movements.id', '=', 'stock_movement_lines.stock_movement_id');

        $includeVoid = (bool) ($filters['include_void'] ?? false);
        if ($includeVoid) {
            $q->whereIn('stock_movements.status', ['posted', 'void']);
        } else {
            $q->where('stock_movements.status', 'posted');
        }

        if (! empty($filters['product_id'])) $q->where('stock_movement_lines.product_id', (int) $filters['product_id']);
        if (! empty($filters['warehouse_id'])) $q->where('stock_movement_lines.warehouse_id', (int) $filters['warehouse_id']);
        if (! empty($filters['start_date'])) $q->whereDate('stock_movements.movement_date', '>=', (string) $filters['start_date']);
        if (! empty($filters['end_date'])) $q->whereDate('stock_movements.movement_date', '<=', (string) $filters['end_date']);

        $row = $q->first();

        return [
            'quantity_in' => (float) ($row?->qty_in ?? 0),
            'quantity_out' => (float) ($row?->qty_out ?? 0),
            'value_in' => (float) ($row?->value_in ?? 0),
            'value_out' => (float) ($row?->value_out ?? 0),
        ];
    }
}

