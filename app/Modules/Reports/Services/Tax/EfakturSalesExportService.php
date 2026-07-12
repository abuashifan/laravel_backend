<?php

namespace App\Modules\Reports\Services\Tax;

use Illuminate\Support\Facades\DB;

/**
 * Ekspor E-Faktur Keluaran / Penjualan — CSV format DJP (Fase 12 T12.1).
 *
 * Skema flat "header-per-faktur" (satu baris per faktur penjualan taxable yang
 * sudah diposting). Sumber & definisi angka sama dengan {@see OutputVatReportService}:
 * DPP = subtotal_after_discount, PPN = tax_total, Total = grand_total.
 *
 * Catatan format:
 * - NOMOR_FAKTUR_PAJAK dikosongkan — ERP belum menyimpan NSFP 16-digit; diisi
 *   manual di aplikasi e-Faktur. REFERENSI memakai nomor faktur komersial.
 * - MASA_PAJAK / TAHUN_PAJAK diturunkan dari tanggal faktur.
 * - Nilai rupiah dibulatkan ke bilangan bulat (DJP tanpa desimal / pemisah ribuan).
 */
class EfakturSalesExportService
{
    /** Status faktur yang dianggap final/terposting (punya jurnal). */
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
        $records = DB::connection('tenant')->table('sales_invoices as si')
            ->leftJoin('contacts as c', 'c.id', '=', 'si.customer_id')
            ->where('si.is_taxable', '=', 1)
            ->whereIn('si.status', self::POSTED_STATUSES)
            ->when($filters['start_date'] ?? null, fn ($q, $v) => $q->whereDate('si.invoice_date', '>=', $v))
            ->when($filters['end_date'] ?? null, fn ($q, $v) => $q->whereDate('si.invoice_date', '<=', $v))
            ->orderBy('si.invoice_date')
            ->orderBy('si.invoice_number')
            ->select([
                'si.invoice_number',
                'si.invoice_date',
                'c.tax_number as npwp',
                'c.name as customer_name',
                'si.subtotal_after_discount as dpp',
                'si.tax_total as ppn',
                'si.grand_total as total',
            ])
            ->get();

        $rows = $records->map(fn ($r) => [
            $r->npwp !== null ? (string) $r->npwp : '',
            $r->customer_name !== null ? (string) $r->customer_name : '',
            '', // NOMOR_FAKTUR_PAJAK — NSFP tidak tersimpan
            (string) $r->invoice_date,
            substr((string) $r->invoice_date, 5, 2),
            substr((string) $r->invoice_date, 0, 4),
            (string) (int) round((float) $r->dpp),
            (string) (int) round((float) $r->ppn),
            (string) (int) round((float) $r->total),
            (string) $r->invoice_number,
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

        return "efaktur-keluaran-{$suffix}.csv";
    }
}
