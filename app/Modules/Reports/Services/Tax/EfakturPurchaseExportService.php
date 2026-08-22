<?php

namespace App\Modules\Reports\Services\Tax;

use Illuminate\Support\Facades\DB;

/**
 * Ekspor E-Faktur Masukan / Pembelian — CSV format DJP (Fase 12 T12.2).
 *
 * Simetris dengan {@see EfakturSalesExportService} tetapi dari vendor_bills.
 * Karena nomor Faktur Pajak vendor tersimpan (`vendor_invoice_number`), kolom
 * NOMOR_FAKTUR_PAJAK diisi darinya. REFERENSI memakai nomor tagihan internal.
 */
class EfakturPurchaseExportService
{
    /** Status tagihan yang dianggap final/terposting (punya jurnal). */
    private const POSTED_STATUSES = ['posted', 'partially_paid', 'paid'];

    /** Kolom CSV baku (urutan tetap). */
    public const HEADERS = [
        'NPWP',
        'NAMA',
        'NOMOR_FAKTUR_PAJAK',
        'TANGGAL_FAKTUR',
        'MASA_PAJAK',
        'TAHUN_PAJAK',
        'DPP',
        'PPN',
        'TOTAL',
        'REFERENSI',
    ];

    /**
     * @param  array{start_date?:string|null, end_date?:string|null}  $filters
     * @return array{filename:string, headers:list<string>, rows:list<list<string>>}
     */
    public function export(array $filters): array
    {
        $records = DB::connection('tenant')->table('vendor_bills as vb')
            ->leftJoin('contacts as c', 'c.id', '=', 'vb.vendor_id')
            ->where('vb.is_taxable', '=', 1)
            ->whereIn('vb.status', self::POSTED_STATUSES)
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('vb.bill_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('vb.bill_date', '<=', $v))
            ->orderBy('vb.bill_date')
            ->orderBy('vb.bill_number')
            ->select([
                'vb.bill_number',
                'vb.bill_date',
                'vb.vendor_invoice_number',
                'c.tax_number as npwp',
                'c.name as vendor_name',
                'vb.subtotal_after_discount as dpp',
                'vb.tax_total as ppn',
                'vb.grand_total as total',
            ])
            ->get();

        $rows = $records->map(fn ($r) => [
            $r->npwp !== null ? (string) $r->npwp : '',
            $r->vendor_name !== null ? (string) $r->vendor_name : '',
            $r->vendor_invoice_number !== null ? (string) $r->vendor_invoice_number : '',
            (string) $r->bill_date,
            substr((string) $r->bill_date, 5, 2),
            substr((string) $r->bill_date, 0, 4),
            (string) (int) round((float) $r->dpp),
            (string) (int) round((float) $r->ppn),
            (string) (int) round((float) $r->total),
            (string) $r->bill_number,
        ])->values()->all();

        return [
            'filename' => $this->filename($filters),
            'headers' => self::HEADERS,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{start_date?:string|null, end_date?:string|null}  $filters
     */
    private function filename(array $filters): string
    {
        $start = $filters['start_date'] ?? null;
        $end = $filters['end_date'] ?? null;
        $suffix = ($start || $end)
            ? str_replace('-', '', (string) ($start ?? 'awal')).'-'.str_replace('-', '', (string) ($end ?? 'akhir'))
            : 'semua';

        return "efaktur-masukan-{$suffix}.csv";
    }
}
