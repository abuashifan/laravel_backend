<?php

namespace App\Modules\Reports\Services\Purchase;

use App\Modules\Purchase\Models\VendorBill;

class PurchaseByVendorReportService
{
    /**
     * @param  array{start_date?: string|null, end_date?: string|null, vendor_id?: int|null, department_id?: int|null, project_id?: int|null}  $filters
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function getReport(array $filters): array
    {
        $rows = VendorBill::query()
            ->where('status', 'posted')
            ->when(
                $filters['start_date'] ?? null,
                fn ($q, $v) => $q->where('bill_date', '>=', $v),
            )
            ->when(
                $filters['end_date'] ?? null,
                fn ($q, $v) => $q->where('bill_date', '<=', $v),
            )
            ->when(
                $filters['vendor_id'] ?? null,
                fn ($q, $v) => $q->where('vendor_id', $v),
            )
            ->join('contacts as c', 'c.id', '=', 'vendor_bills.vendor_id')
            ->selectRaw('
                vendor_bills.vendor_id,
                c.name as vendor_name,
                COUNT(*) as bill_count,
                COALESCE(SUM(vendor_bills.subtotal_after_discount), 0) as subtotal,
                COALESCE(SUM(vendor_bills.tax_total), 0) as tax,
                COALESCE(SUM(vendor_bills.grand_total), 0) as total
            ')
            ->groupBy('vendor_bills.vendor_id', 'c.name')
            ->orderBy('total', 'desc')
            ->get()
            ->map(fn ($row) => [
                'vendor_id' => (int) $row->vendor_id,
                'vendor_name' => (string) $row->vendor_name,
                'bill_count' => (int) $row->bill_count,
                'subtotal' => (float) $row->subtotal,
                'tax' => (float) $row->tax,
                'total' => (float) $row->total,
            ])
            ->values()
            ->all();

        $totals = [
            'bill_count' => array_sum(array_column($rows, 'bill_count')),
            'subtotal' => array_sum(array_column($rows, 'subtotal')),
            'tax' => array_sum(array_column($rows, 'tax')),
            'total' => array_sum(array_column($rows, 'total')),
        ];

        return compact('rows', 'totals');
    }
}
