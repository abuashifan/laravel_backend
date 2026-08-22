<?php

namespace App\Modules\Reports\Services\Tax;

use Illuminate\Support\Facades\DB;

/**
 * Daftar PPN Masukan (Fase 11 T11.2).
 *
 * Sumber: faktur pembelian (vendor_bills) yang taxable & sudah diposting.
 * DPP = subtotal_after_discount, PPN = tax_total, Total = grand_total.
 * `vendor_invoice_number` disertakan sebagai nomor Faktur Pajak vendor.
 */
class InputVatReportService
{
    private const POSTED_STATUSES = ['posted', 'partially_paid', 'paid'];

    /**
     * @param  array{start_date?:string|null, end_date?:string|null}  $filters
     * @return array{rows: list<array<string,mixed>>, totals: array<string,mixed>, filter: array<string,mixed>}
     */
    public function getReport(array $filters): array
    {
        $rows = DB::connection('tenant')->table('vendor_bills as vb')
            ->leftJoin('contacts as c', 'c.id', '=', 'vb.vendor_id')
            ->where('vb.is_taxable', '=', 1)
            ->whereIn('vb.status', self::POSTED_STATUSES)
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('vb.bill_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('vb.bill_date', '<=', $v))
            ->orderBy('vb.bill_date')
            ->orderBy('vb.bill_number')
            ->select([
                'vb.id',
                'vb.bill_number',
                'vb.bill_date',
                'vb.vendor_invoice_number',
                'c.name as vendor_name',
                'vb.subtotal_after_discount as dpp',
                'vb.tax_total as ppn',
                'vb.grand_total as total',
            ])
            ->get()
            ->map(fn ($r) => [
                'id' => (int) $r->id,
                'bill_number' => (string) $r->bill_number,
                'bill_date' => (string) $r->bill_date,
                'vendor_invoice_number' => $r->vendor_invoice_number !== null ? (string) $r->vendor_invoice_number : null,
                'vendor_name' => $r->vendor_name !== null ? (string) $r->vendor_name : null,
                'dpp' => (float) $r->dpp,
                'ppn' => (float) $r->ppn,
                'total' => (float) $r->total,
            ])
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'totals' => [
                'bill_count' => count($rows),
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
