<?php

namespace App\Shared\TransactionLifecycle;

use Closure;

/**
 * Pemeriksaan yang ditunda sampai sebuah unit kerja selesai, lalu dinilai
 * dari HASIL AKHIR-nya. Dipakai saat dokumen posted diedit: efek lama dibalik
 * dulu baru diposting ulang, jadi langkah antaranya bisa sempat melanggar
 * aturan (stok minus) walau hasil akhirnya sah -- mis. penyesuaian +100
 * diubah jadi +120 setelah 15 unit terjual.
 *
 * `run()` harus dipanggil di dalam transaksi database supaya pemeriksaan
 * akhir yang gagal ikut membatalkan seluruh perubahan.
 */
final class DeferredChecks
{
    private static int $depth = 0;

    /** @var array<string, Closure(): void> */
    private static array $checks = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $work
     * @return T
     */
    public static function run(Closure $work): mixed
    {
        self::$depth++;
        try {
            $result = $work();
            if (self::$depth === 1) {
                foreach (self::$checks as $check) {
                    $check();
                }
            }

            return $result;
        } finally {
            self::$depth--;
            if (self::$depth === 0) {
                self::$checks = [];
            }
        }
    }

    public static function active(): bool
    {
        return self::$depth > 0;
    }

    /** Daftarkan pemeriksaan; kunci yang sama cukup diperiksa sekali. */
    public static function defer(string $key, Closure $check): void
    {
        self::$checks[$key] = $check;
    }
}
