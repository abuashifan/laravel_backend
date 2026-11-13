<?php

namespace App\Modules\Pos\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Hari toko menurut zona waktu toko (bawaan WIB), bukan UTC server.
 * Tanpa ini, transaksi jam 00.00-07.00 WIB jatuh ke tanggal kemarin di
 * filter, laporan harian, dan nomor dokumen.
 */
class PosClock
{
    public const TIMEZONES = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];

    public function __construct(private readonly PosSettingService $settings) {}

    /** Dibaca sekali per instance (satu request): laporan memanggilnya per baris. */
    private ?string $timezone = null;

    public function timezone(): string
    {
        if ($this->timezone === null) {
            $timezone = $this->settings->get('timezone');
            $this->timezone = in_array($timezone, self::TIMEZONES, true) ? $timezone : 'Asia/Jakarta';
        }

        return $this->timezone;
    }

    /** Awal hari toko untuk tanggal `Y-m-d`, dalam zona waktu aplikasi (untuk kueri). */
    public function startOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone())->startOfDay()->setTimezone((string) config('app.timezone'));
    }

    public function endOfDay(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, $this->timezone())->endOfDay()->setTimezone((string) config('app.timezone'));
    }

    public function today(): string
    {
        return CarbonImmutable::now($this->timezone())->toDateString();
    }

    /** Format waktu tersimpan menurut jam toko. */
    public function format(DateTimeInterface $moment, string $format): string
    {
        return CarbonImmutable::instance($moment)->setTimezone($this->timezone())->format($format);
    }

    /**
     * Ekspresi SQL untuk tanggal toko (`YYYY-MM-DD`) dari kolom waktu yang
     * tersimpan dalam zona aplikasi. Dipakai supaya pengelompokan per hari
     * terjadi di database, bukan dengan menarik setiap baris ke PHP.
     *
     * Zona Indonesia tidak punya waktu musim panas, jadi selisihnya tetap
     * (menit) dan cukup digeser. `$column` harus nama kolom yang aman (konstanta
     * kode, bukan input pengguna).
     */
    public function localDateSql(string $column): string
    {
        $shift = $this->offsetMinutes();

        return DB::connection('pos')->getDriverName() === 'pgsql'
            ? sprintf("to_char(%s + interval '%d minutes', 'YYYY-MM-DD')", $column, $shift)
            : sprintf("strftime('%%Y-%%m-%%d', %s, '%+d minutes')", $column, $shift);
    }

    /** Jam toko (0-23) sebagai bilangan bulat; lihat `localDateSql()`. */
    public function localHourSql(string $column): string
    {
        $shift = $this->offsetMinutes();

        return DB::connection('pos')->getDriverName() === 'pgsql'
            ? sprintf("CAST(EXTRACT(HOUR FROM %s + interval '%d minutes') AS INTEGER)", $column, $shift)
            : sprintf("CAST(strftime('%%H', %s, '%+d minutes') AS INTEGER)", $column, $shift);
    }

    /** Selisih zona toko terhadap zona aplikasi, dalam menit. */
    private function offsetMinutes(): int
    {
        $now = CarbonImmutable::now();

        return (int) ($now->setTimezone($this->timezone())->utcOffset() - $now->setTimezone((string) config('app.timezone'))->utcOffset());
    }
}
