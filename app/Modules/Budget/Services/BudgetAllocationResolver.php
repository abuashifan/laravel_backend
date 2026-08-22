<?php

namespace App\Modules\Budget\Services;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use Carbon\CarbonImmutable;

/**
 * Satu aturan alokasi periode yang berlaku di peringatan over-budget MAUPUN
 * laporan perbandingan — G6.
 *
 * | Jenis baris                | Pembanding actual                                   |
 * |----------------------------|-----------------------------------------------------|
 * | `period_month = '2026-09'` | Actual bulan September saja                         |
 * | `period_month = NULL`      | Actual kumulatif `period_from` s/d akhir bulan transaksi |
 *
 * Sebelumnya anggaran setahun dibandingkan dengan actual satu bulan, jadi
 * anggaran gaji 240 juta setahun baru menyala kalau satu bulan saja tembus 240
 * juta — praktis tidak pernah. Sekarang ia menyala saat akumulasi Jan–Sep
 * melewati 240 juta.
 */
class BudgetAllocationResolver
{
    /** Baris tahunan muncul apa adanya, tidak dipecah jadi angka bulanan palsu. */
    public const ANNUAL_ROW = 'annual_row';

    /** Opsi TAMPILAN: baris tahunan diratakan per bulan. Bukan default. */
    public const EVEN = 'even';

    /** @return array<int,string> */
    public static function modes(): array
    {
        return [self::ANNUAL_ROW, self::EVEN];
    }

    /**
     * Rentang tanggal actual yang sebanding dengan sebuah baris anggaran, saat
     * transaksinya jatuh di bulan `$asOfMonth`.
     *
     * @param  string  $asOfMonth  'YYYY-MM'
     * @return array{0:string,1:string} [from, to] dalam format Y-m-d
     */
    public function comparableRange(BudgetLine $line, BudgetPeriod $period, string $asOfMonth): array
    {
        $monthEnd = CarbonImmutable::parse($asOfMonth.'-01')->endOfMonth();
        $periodTo = CarbonImmutable::parse($period->period_to);
        $to = $monthEnd->greaterThan($periodTo) ? $periodTo : $monthEnd;

        if ($line->period_month === null) {
            // Baris tahunan: kumulatif sejak awal periode anggaran.
            return [CarbonImmutable::parse($period->period_from)->toDateString(), $to->toDateString()];
        }

        $lineStart = CarbonImmutable::parse($line->period_month.'-01');

        return [$lineStart->toDateString(), $lineStart->endOfMonth()->toDateString()];
    }

    /**
     * Nominal anggaran yang jatuh pada satu bulan tertentu.
     *
     * Pada mode `annual_row` (default) baris tahunan TIDAK dipecah — ia
     * dikembalikan 0 untuk bulan mana pun dan dilaporkan terpisah sebagai
     * "Tahunan (belum dialokasikan)". Mesin tetap jujur; pemerataan adalah
     * keputusan penyajian, bukan fakta anggaran.
     */
    public function amountForMonth(BudgetLine $line, BudgetPeriod $period, string $month, string $mode = self::ANNUAL_ROW): float
    {
        $amount = (float) $line->amount;

        if ($line->period_month !== null) {
            return $line->period_month === $month ? $amount : 0.0;
        }

        if ($mode !== self::EVEN) {
            return 0.0;
        }

        $monthCount = max(1, count($this->monthsIn($period)));

        return $amount / $monthCount;
    }

    /**
     * Bulan-bulan yang tercakup periode anggaran, 'YYYY-MM' berurutan.
     *
     * Dipakai untuk mengelompokkan actual per bulan lewat rentang tanggal —
     * `strftime()` hanya ada di SQLite (G9).
     *
     * @return array<int,string>
     */
    public function monthsIn(BudgetPeriod $period, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $start = CarbonImmutable::parse($dateFrom ?? $period->period_from)->startOfMonth();
        $end = CarbonImmutable::parse($dateTo ?? $period->period_to)->startOfMonth();

        $months = [];
        for ($cursor = $start; $cursor->lessThanOrEqualTo($end); $cursor = $cursor->addMonth()) {
            $months[] = $cursor->format('Y-m');
        }

        return $months;
    }

    /**
     * Batas tanggal satu bulan, dipotong rentang yang diminta.
     *
     * @return array{0:string,1:string}
     */
    public function monthBounds(string $month, string $rangeFrom, string $rangeTo): array
    {
        $monthStart = CarbonImmutable::parse($month.'-01');
        $from = $monthStart->lessThan(CarbonImmutable::parse($rangeFrom)) ? CarbonImmutable::parse($rangeFrom) : $monthStart;
        $monthEnd = $monthStart->endOfMonth();
        $to = $monthEnd->greaterThan(CarbonImmutable::parse($rangeTo)) ? CarbonImmutable::parse($rangeTo) : $monthEnd;

        return [$from->toDateString(), $to->toDateString()];
    }
}
