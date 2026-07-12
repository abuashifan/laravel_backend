<?php

namespace App\Modules\Reports\Services\Tax;

use Illuminate\Support\Facades\DB;

/**
 * Daftar PPN Keluaran (Fase 11 T11.1).
 *
 * Sumber: faktur penjualan (sales_invoices) yang taxable & sudah diposting.
 * DPP = subtotal_after_discount, PPN = tax_total, Total = grand_total.
 * Status posted-family (posted/partially_paid/paid) diikutkan — faktur yang sudah
 * dibayar tetap wajib muncul di daftar PPN.
 */
class OutputVatReportService
{
    /** Status faktur yang dianggap final/terposting (punya jurnal). */
    private const POSTED_STATUSES = ['posted', 'partially_paid', 'paid'];

    /**
     * @param  array{start_date?:string|null, end_date?:string|null}  $filters
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>, filter: array<string,mixed>}
     */
    public function getReport(array $filters): array
    {
        $rows = DB::connection('tenant')->table('sales_invoices as si')
            ->leftJoin('contacts as c', 'c.id', '=', 'si.customer_id')
            ->where('si.is_taxable', '=', 1)
            ->whereIn('si.status', self::POSTED_STATUSES)
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('si.invoice_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('si.invoice_date', '<=', $v))
            ->orderBy('si.invoice_date')
            ->orderBy('si.invoice_number')
            ->select([
                'si.id',
                'si.invoice_number',
                'si.invoice_date',
                'c.name as customer_name',
                'si.subtotal_after_discount as dpp',
                'si.tax_total as ppn',
                'si.grand_total as total',
            ])
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'invoice_number' => (string) $r->invoice_number,
                'invoice_date' => (string) $r->invoice_date,
                'customer_name' => $r->customer_name !== null ? (string) $r->customer_name : null,
                'dpp' => (float) $r->dpp,
                'ppn' => (float) $r->ppn,
                'total' => (float) $r->total,
            ])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'invoice_count' => count($rows),
                'dpp' => round(array_sum(array_column($rows, 'dpp')), 2),
                'ppn' => round(array_sum(array_column($rows, 'ppn')), 2),
                'total' => round(array_sum(array_column($rows, 'total')), 2),
            ],
            'filter' => [
                'start_date' => $filters['start_date'] ?? null,
                'end_date' => $filters['end_date'] ?? null,
            ],
        ];
    }
}
