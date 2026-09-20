<?php

namespace App\Modules\Reports\Services\Sales;

use App\Modules\Sales\Models\SalesInvoice;
use App\Shared\Database\DatePeriodExpression;

class SalesSummaryReportService
{
    /**
     * @param  array{start_date?: string|null, end_date?: string|null, group_by?: string|null, department_id?: int|null, project_id?: int|null}  $filters
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function getReport(array $filters): array
    {
        $groupBy = $filters['group_by'] ?? 'month';

        [$periodSelectExpr, $periodGroupExpr] = DatePeriodExpression::for(
            (new SalesInvoice)->getConnection()->getDriverName(),
            'invoice_date',
            $groupBy,
        );

        $query = SalesInvoice::query()
            ->where('status', 'posted')
            ->when(
                $filters['start_date'] ?? null,
                fn ($q, $v) => $q->where('invoice_date', '>=', $v),
            )
            ->when(
                $filters['end_date'] ?? null,
                fn ($q, $v) => $q->where('invoice_date', '<=', $v),
            )
            ->selectRaw("
                COUNT(*) as invoice_count,
                COALESCE(SUM(subtotal_after_discount), 0) as subtotal,
                COALESCE(SUM(tax_total), 0) as tax,
                COALESCE(SUM(grand_total), 0) as total,
                {$periodSelectExpr}
            ")
            ->groupByRaw($periodGroupExpr)
            ->orderByRaw($periodGroupExpr);

        $rows = $query->get()->map(fn ($row) => [
            'period' => $row->period,
            'invoice_count' => (int) $row->invoice_count,
            'subtotal' => (float) $row->subtotal,
            'tax' => (float) $row->tax,
            'total' => (float) $row->total,
        ])->values()->all();

        $totals = [
            'invoice_count' => array_sum(array_column($rows, 'invoice_count')),
            'subtotal' => array_sum(array_column($rows, 'subtotal')),
            'tax' => array_sum(array_column($rows, 'tax')),
            'total' => array_sum(array_column($rows, 'total')),
        ];

        return compact('rows', 'totals');
    }
}
