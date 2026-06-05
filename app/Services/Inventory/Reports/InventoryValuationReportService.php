<?php

namespace App\Services\Inventory\Reports;

use App\Services\Inventory\InventoryValuationService;

class InventoryValuationReportService
{
    public function __construct(private readonly InventoryValuationService $valuation) {}

    public function current(array $filters = []): array
    {
        return $this->valuation->currentValuation($filters);
    }

    public function asOf(string $date, array $filters = []): array
    {
        return $this->valuation->valuationAsOf($date, $filters);
    }

    public function summaryByWarehouse(array $filters = []): array
    {
        $val = $this->valuation->currentValuation($filters);
        $groups = [];
        foreach ($val['rows'] as $r) {
            $wid = (int) ($r['warehouse_id'] ?? 0);
            $groups[$wid] ??= [
                'warehouse_id' => $wid,
                'warehouse_code' => $r['warehouse_code'] ?? null,
                'warehouse_name' => $r['warehouse_name'] ?? null,
                'total_quantity' => 0.0,
                'total_value' => 0.0,
            ];
            $groups[$wid]['total_quantity'] += (float) ($r['quantity_on_hand'] ?? 0);
            $groups[$wid]['total_value'] += (float) ($r['total_value'] ?? 0);
        }

        return [
            'filters' => $filters,
            'rows' => array_values($groups),
        ];
    }

    public function summaryByCategory(array $filters = []): array
    {
        $val = $this->valuation->currentValuation($filters);
        $groups = [];
        foreach ($val['rows'] as $r) {
            $cid = (int) ($r['category_id'] ?? 0);
            $groups[$cid] ??= [
                'category_id' => $cid ?: null,
                'total_quantity' => 0.0,
                'total_value' => 0.0,
            ];
            $groups[$cid]['total_quantity'] += (float) ($r['quantity_on_hand'] ?? 0);
            $groups[$cid]['total_value'] += (float) ($r['total_value'] ?? 0);
        }

        return [
            'filters' => $filters,
            'rows' => array_values($groups),
        ];
    }
}

