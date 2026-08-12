<?php

namespace App\Modules\Imports\Services\Committers\Concerns;

use Carbon\CarbonImmutable;

/**
 * Menormalkan tanggal dari format CSV (DD/MM/YYYY) ke format database (Y-m-d).
 *
 * Dipakai oleh semua committer transaksi yang menerima kolom tanggal dari berkas
 * impor — jurnal umum, faktur penjualan, tagihan pembelian.
 */
trait NormalizesImportDates
{
    /**
     * Parse DD/MM/YYYY dengan validasi kalender penuh. `Carbon::createFromFormat`
     * sendiri TIDAK menolak tanggal yang overflow (mis. 31/04 atau bulan 13) —
     * ia diam-diam menggeser ke tanggal lain yang valid. Karena itu komponen
     * tanggal divalidasi dulu dengan `checkdate()` sebelum di-parse.
     */
    private function parseImportDate(string $value): ?CarbonImmutable
    {
        if (! preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches)) {
            return null;
        }

        [, $day, $month, $year] = $matches;

        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }

        $parsed = CarbonImmutable::createFromFormat('d/m/Y', $value);

        return $parsed !== false ? $parsed : null;
    }

    /**
     * Konversi DD/MM/YYYY → Y-m-d. String kosong atau tidak valid dikembalikan
     * sebagai string kosong — validasi row-level sudah menangkapnya sebelumnya.
     */
    private function normalizeDate(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return $this->parseImportDate($value)?->format('Y-m-d') ?? '';
    }
}
