<?php

namespace App\Modules\Reports\Services\Sales;

use App\Modules\Sales\Models\SalesInvoiceLine;

class SalesByProductReportService
{
    /**
     * @param  array{start_date?: string|null, end_date?: string|null, product_id?: int|null, department_id?: int|null, project_id?: int|null}  $filters
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function getReport(array $filters): array
    {
        $rows = SalesInvoiceLine::query()
            ->join('sales_invoices as si', 'si.id', '=', 'sales_invoice_lines.sales_invoice_id')
            ->where('si.status', 'posted')
            ->when(
                $filters['start_date'] ?? null,
                fn ($q, $v) => $q->where('si.invoice_date', '>=', $v),
            )
            ->when(
                $filters['end_date'] ?? null,
                fn ($q, $v) => $q->where('si.invoice_date', '<=', $v),
            )
            ->when(
                $filters['product_id'] ?? null,
                fn ($q, $v) => $q->where('sales_invoice_lines.product_id', $v),
            )
            ->whereNotNull('sales_invoice_lines.product_id')
            ->join('products as p', 'p.id', '=', 'sales_invoice_lines.product_id')
            ->selectRaw('
                sales_invoice_lines.product_id,
                COALESCE(sales_invoice_lines.product_code, p.product_code) as product_code,
                p.product_name,
                COALESCE(SUM(sales_invoice_lines.quantity), 0) as qty,
                COALESCE(SUM(sales_invoice_lines.subtotal_after_discount), 0) as subtotal,
                COALESCE(SUM(sales_invoice_lines.line_total), 0) as total
            ')
            ->groupBy('sales_invoice_lines.product_id', 'sales_invoice_lines.product_code', 'p.product_name', 'p.product_code')
            ->orderBy('total', 'desc')
            ->get()
            ->map(fn ($row) => [
                'product_id' => (int) $row->product_id,
                'product_code' => (string) $row->product_code,
                'product_name' => (string) $row->product_name,  // aliased from p.product_name
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
