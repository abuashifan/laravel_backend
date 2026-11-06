<?php

namespace App\Modules\Pos\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Hari toko menurut zona waktu toko (bawaan WIB), bukan UTC server.
 * Tanpa ini, transaksi jam 00.00-07.00 WIB jatuh ke tanggal kemarin di
 * filter, laporan harian, dan nomor dokumen.
 */
class PosClock
{
    public const TIMEZONES = ['Asia/Jakarta', 'Asia/Makassar', 'Asia/Jayapura'];

    public function __construct(private readonly PosSettingService $settings) {}

    public function timezone(): string
    {
        $timezone = $this->settings->get('timezone');

        return in_array($timezone, self::TIMEZONES, true) ? $timezone : 'Asia/Jakarta';
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
}
