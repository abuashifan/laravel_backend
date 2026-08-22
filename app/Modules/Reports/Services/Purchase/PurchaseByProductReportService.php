<?php

namespace App\Modules\Reports\Services\Purchase;

use App\Modules\Purchase\Models\VendorBillLine;

class PurchaseByProductReportService
{
    /**
     * @param  array{start_date?: string|null, end_date?: string|null, product_id?: int|null, department_id?: int|null, project_id?: int|null}  $filters
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function getReport(array $filters): array
    {
        $rows = VendorBillLine::query()
            ->join('vendor_bills as vb', 'vb.id', '=', 'vendor_bill_lines.vendor_bill_id')
            ->where('vb.status', 'posted')
            ->when(
                $filters['start_date'] ?? null,
                fn ($q, $v) => $q->where('vb.bill_date', '>=', $v),
            )
            ->when(
                $filters['end_date'] ?? null,
                fn ($q, $v) => $q->where('vb.bill_date', '<=', $v),
            )
            ->when(
                $filters['product_id'] ?? null,
                fn ($q, $v) => $q->where('vendor_bill_lines.product_id', $v),
            )
            ->whereNotNull('vendor_bill_lines.product_id')
            ->join('products as p', 'p.id', '=', 'vendor_bill_lines.product_id')
            ->selectRaw('
                vendor_bill_lines.product_id,
                COALESCE(vendor_bill_lines.product_code, p.product_code) as product_code,
                p.product_name,
                COALESCE(SUM(vendor_bill_lines.quantity), 0) as qty,
                COALESCE(SUM(vendor_bill_lines.subtotal_after_discount), 0) as subtotal,
                COALESCE(SUM(vendor_bill_lines.line_total), 0) as total
            ')
            ->groupBy('vendor_bill_lines.product_id', 'vendor_bill_lines.product_code', 'p.product_name', 'p.product_code')
            ->orderBy('total', 'desc')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,
                'qty' => (float) $row->qty,
                'subtotal' => (float) $row->subtotal,
                'total' => (float) $row->total,
            ])
            ->values()
            ->all();

        $totals = [
            'qty' => array_sum(array_column($rows, 'qty')),
            'subtotal' => array_sum(array_column($rows, 'subtotal')),
            'total' => array_sum(array_column($rows, 'total')),
        ];

        return compact('rows', 'totals');
    }
}
