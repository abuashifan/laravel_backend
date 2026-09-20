<?php

namespace App\Shared\Database;

/**
 * Ekspresi SQL untuk mengelompokkan baris per hari atau per bulan.
 *
 * Pemformatan tanggal adalah salah satu dari sedikit hal yang TIDAK distandarkan
 * antar database, dan tidak ada abstraksi Laravel untuknya — jadi setiap laporan
 * yang mengelompokkan per periode harus memilih sendiri sesuai driver. Sebelum
 * kelas ini pilihannya disalin di tiap service, dan salinan itu hanya mengenal
 * SQLite dan MySQL. Begitu tenant pindah ke Postgres, cabang `default`-nya
 * mengirim `DATE_FORMAT()` ke Postgres — fungsi yang tidak ada di sana, jadi
 * laporannya error, bukan sekadar salah format.
 *
 * Nama kolom TIDAK boleh berasal dari input user: nilainya disisipkan langsung
 * ke SQL karena nama kolom tidak bisa dikirim sebagai parameter terikat.
 */
class DatePeriodExpression
{
    /**
     * @param  string  $driver  hasil `getConnection()->getDriverName()`
     * @param  string  $column  nama kolom tanggal, selalu konstanta di kode
     * @param  string  $groupBy  'day' atau 'month' (selain itu dianggap 'month')
     * @return array{0: string, 1: string} [ekspresi select (beralias `period`), ekspresi group by]
     */
    public static function for(string $driver, string $column, string $groupBy): array
    {
        $byDay = $groupBy === 'day';

        $expression = match ($driver) {
            'sqlite' => sprintf("strftime('%s', %s)", $byDay ? '%Y-%m-%d' : '%Y-%m', $column),
            'pgsql' => sprintf("to_char(%s, '%s')", $column, $byDay ? 'YYYY-MM-DD' : 'YYYY-MM'),
            default => sprintf("DATE_FORMAT(%s, '%s')", $column, $byDay ? '%Y-%m-%d' : '%Y-%m'),
        };

        return [$expression.' as period', $expression];
    }
}
