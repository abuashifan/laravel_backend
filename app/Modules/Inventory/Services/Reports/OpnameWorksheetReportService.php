<?php

namespace App\Modules\Inventory\Services\Reports;

use App\Modules\Inventory\Models\StockOpname;

/**
 * Kertas Kerja Fisikal / Opname Worksheet (Fase 8 T8.3).
 *
 * Menyajikan qty sistem vs qty fisik vs selisih per produk/gudang untuk satu sesi opname.
 * Bila `opname_id` tidak diberikan, dipilih opname terbaru (by opname_date, lalu id) yang
 * cocok dengan filter warehouse_id/status opsional.
 */
class OpnameWorksheetReportService
{
    /**
     * @param  array<string,mixed>  $filters
     * @return array<string,mixed>
     */
    public function report(array $filters = []): array
    {
        $opname = $this->resolveOpname($filters);

        if ($opname === null) {
            return [
                'opname' => null,
                'filters' => $filters,
                'rows' => [],
                'totals' => $this->emptyTotals(),
            ];
        }

        $rows = [];
        foreach ($opname->lines as $line) {
            $system = (float) $line->system_quantity;
            $physical = $line->physical_quantity !== null ? (float) $line->physical_quantity : null;
            $difference = (float) $line->difference_quantity;
            $valueDiff = (float) $line->difference_value;

            $rows[] = [
                'product_id' => (int) $line->product_id,
                'product_code' => $line->product?->product_code,
                'product_name' => $line->product?->product_name,
                'warehouse_id' => (int) $line->warehouse_id,
                'warehouse_name' => $line->warehouse?->name,
                'unit_name' => $line->unit?->name,
                'system_quantity' => $system,
                'physical_quantity' => $physical,
                'difference_quantity' => $difference,
                'average_cost' => (float) $line->average_cost,
                'difference_value' => $valueDiff,
                'counted' => $physical !== null,
            ];
        }

        return [
            'opname' => [
                'id' => (int) $opname->id,
                'opname_number' => (string) $opname->opname_number,
                'opname_date' => $opname->opname_date?->toDateString(),
                'status' => (string) $opname->status,
                'warehouse_id' => (int) $opname->warehouse_id,
                'warehouse_name' => $opname->warehouse?->name,
            ],
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function resolveOpname(array $filters): ?StockOpname
    {
        $query = StockOpname::query()->with(['lines.product', 'lines.warehouse', 'lines.unit', 'warehouse']);

        if (! empty($filters['opname_id'])) {
            return $query->whereKey((int) $filters['opname_id'])->first();
        }

        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->orderByDesc('opname_date')->orderByDesc('id')->first();
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function totals(array $rows): array
    {
        $system = 0.0;
        $physical = 0.0;
        $difference = 0.0;
        $valueDiff = 0.0;
        $countedLines = 0;

        foreach ($rows as $r) {
            $system += (float) $r['system_quantity'];
            $physical += (float) ($r['physical_quantity'] ?? 0);
            $difference += (float) $r['difference_quantity'];
            $valueDiff += (float) $r['difference_value'];
            if ($r['counted']) {
                $countedLines++;
            }
        }

        return [
            'line_count' => count($rows),
            'counted_lines' => $countedLines,
            'total_system_quantity' => $system,
            'total_physical_quantity' => $physical,
            'total_difference_quantity' => $difference,
            'total_difference_value' => round($valueDiff, (int) config('inventory.amount_precision', 2)),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyTotals(): array
    {
        return [
            'line_count' => 0,
            'counted_lines' => 0,
            'total_system_quantity' => 0.0,
            'total_physical_quantity' => 0.0,
            'total_difference_quantity' => 0.0,
            'total_difference_value' => 0.0,
        ];
    }
}
