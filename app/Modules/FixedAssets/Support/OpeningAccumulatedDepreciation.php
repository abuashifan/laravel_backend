<?php

namespace App\Modules\FixedAssets\Support;

use Carbon\Carbon;

/**
 * Estimasi akumulasi penyusutan sebuah aset warisan per tanggal saldo awal.
 *
 * Dipakai dua tempat yang harus sepakat: peringatan di pratinjau impor
 * (`FixedAssetOpeningImportCommitter::warnRow()`) dan pengisian otomatis saat
 * aset saldo awal diaktifkan (`FixedAssetService::activateOpeningAssets()`).
 * Kalau dua tempat itu memakai rumus sendiri-sendiri, user akan diperingatkan
 * soal angka yang berbeda dari yang akhirnya dipakai sistem.
 *
 * ── Kenapa hitungannya boleh diganti user ───────────────────────────────────
 *
 * Rumus di sini garis lurus murni. Pembukuan lama klien belum tentu begitu:
 * saldo menurun, penyusutan yang sempat dihentikan, revaluasi, atau aset yang
 * sudah berhenti disusutkan padahal umurnya belum habis. Karena itu angkanya
 * hanya DEFAULT — kolom akumulasi yang diisi user selalu menang, dan
 * penyimpangannya hanya diperingatkan, tidak ditolak.
 */
final class OpeningAccumulatedDepreciation
{
    /** Ambang peringatan penyimpangan, relatif terhadap estimasi. */
    public const DEVIATION_TOLERANCE = 0.10;

    /**
     * Jumlah bulan yang SUDAH tersusutkan sebelum bulan tanggal saldo awal.
     *
     * Sejajar dengan dua aturan yang sudah berlaku di modul ini:
     * penyusutan dimulai satu bulan setelah tanggal mulai pakai
     * (`FixedAssetService::assetPayload()`), dan jadwal aset saldo awal dimulai
     * TEPAT di bulan tanggal saldo awal (`generateOpeningSchedules()`). Jadi
     * yang dihitung di sini adalah bulan-bulan di antara keduanya.
     */
    public static function monthsElapsed(?string $serviceStartDate, string $openingDate): ?int
    {
        if (! $serviceStartDate) {
            return null;
        }

        $firstPeriod = Carbon::parse($serviceStartDate)->addMonthNoOverflow()->startOfMonth();
        $openingMonth = Carbon::parse($openingDate)->startOfMonth();

        if ($openingMonth->lte($firstPeriod)) {
            return 0;
        }

        return (int) $firstPeriod->diffInMonths($openingMonth);
    }

    /**
     * Estimasi akumulasi penyusutan garis lurus per tanggal saldo awal, atau
     * null kalau tidak bisa dihitung (aset tidak menyusut, umur manfaat atau
     * tanggal mulai pakai belum diketahui).
     *
     * Selalu dibatasi di dasar penyusutan: aset yang umurnya sudah habis jauh
     * sebelum tanggal saldo awal berhenti di `cost - salvage`, bukan melampauinya.
     */
    public static function estimate(
        float $acquisitionCost,
        float $salvageValue,
        ?int $usefulLifeYears,
        ?string $serviceStartDate,
        string $openingDate,
    ): ?float {
        if (! $usefulLifeYears || $usefulLifeYears <= 0) {
            return null;
        }

        $months = self::monthsElapsed($serviceStartDate, $openingDate);
        if ($months === null) {
            return null;
        }

        $basis = round($acquisitionCost - min($salvageValue, $acquisitionCost), 2);
        if ($basis <= 0) {
            return 0.0;
        }

        $monthly = $basis / ($usefulLifeYears * 12);

        return round(min($monthly * $months, $basis), 2);
    }

    /**
     * Apakah angka yang diisi user menyimpang cukup jauh dari estimasi untuk
     * dilaporkan? Estimasi nol diperlakukan khusus — pembagian relatif tidak
     * punya arti di sana, jadi angka apa pun di atas nol dianggap menyimpang.
     */
    public static function deviates(float $actual, float $estimate): bool
    {
        if ($estimate <= 0.0) {
            return $actual > 0.0;
        }

        return abs($actual - $estimate) / $estimate > self::DEVIATION_TOLERANCE;
    }
}
