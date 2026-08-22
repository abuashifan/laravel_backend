<?php

namespace App\Modules\Reports\Services\Sales;

use App\Modules\Sales\Models\SalesInvoice;

class SalesByCustomerReportService
{
    /**
     * @param  array{start_date?: string|null, end_date?: string|null, customer_id?: int|null, department_id?: int|null, project_id?: int|null}  $filters
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function getReport(array $filters): array
    {
        $rows = SalesInvoice::query()
            ->where('status', 'posted')
            ->when(
                $filters['start_date'] ?? null,
                fn ($q, $v) => $q->where('invoice_date', '>=', $v),
            )
            ->when(
                $filters['end_date'] ?? null,
                fn ($q, $v) => $q->where('invoice_date', '<=', $v),
            )
            ->when(
                $filters['customer_id'] ?? null,
                fn ($q, $v) => $q->where('customer_id', $v),
            )
            ->join('contacts as c', 'c.id', '=', 'sales_invoices.customer_id')
            ->selectRaw('
                sales_invoices.customer_id,
                c.name as customer_name,
                COUNT(*) as invoice_count,
                COALESCE(SUM(sales_invoices.subtotal_after_discount), 0) as subtotal,
                COALESCE(SUM(sales_invoices.tax_total), 0) as tax,
                COALESCE(SUM(sales_invoices.grand_total), 0) as total
            ')
            ->groupBy('sales_invoices.customer_id', 'c.name')
            ->orderBy('total', 'desc')
            ->get()
            ->map(fn ($row) => [
                'customer_id' => (int) $row->customer_id,
                'customer_name' => (string) $row->customer_name,
                'invoice_count' => (int) $row->invoice_count,
                'subtotal' => (float) $row->subtotal,
                'tax' => (float) $row->tax,
                'total' => (float) $row->total,
            ])
            ->values()
            ->all();

        $totals = [
            'invoice_count' => array_sum(array_column($rows, 'invoice_count')),
            'subtotal' => array_sum(array_column($rows, 'subtotal')),
            'tax' => array_sum(array_column($rows, 'tax')),
            'total' => array_sum(array_column($rows, 'total')),
        ];

        return compact('rows', 'totals');
    }
}
