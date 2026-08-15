<?php

namespace App\Modules\Budget\Support;

/**
 * Status satu baris perbandingan anggaran vs actual.
 *
 * Favorable/unfavorable bergantung arah baris — untuk beban, actual di bawah
 * anggaran itu bagus; untuk pendapatan, sebaliknya. `BudgetActualService` sudah
 * membalik tanda actual pendapatan (credit − debit), jadi kedua sisi memakai
 * perbandingan yang sama dan tidak ada rumus khusus per view.
 */
class BudgetState
{
    /** Actual = anggaran, dalam toleransi. */
    public const ON_BUDGET = 'on_budget';

    /** Favorable: hemat (beban) atau target terlampaui (pendapatan). */
    public const UNDER_BUDGET = 'under_budget';

    /** Unfavorable: boros (beban) atau target meleset (pendapatan). */
    public const OVER_BUDGET = 'over_budget';

    /** Ada actual, tapi tidak ada anggarannya. */
    public const NO_BUDGET = 'no_budget';

    /** Ada anggaran, tapi belum ada realisasi. */
    public const NO_ACTUAL = 'no_actual';

    /**
     * Selisih di bawah nilai ini dianggap sama. Anggaran disimpan `decimal(20,2)`
     * sehingga selisih pembulatan float tidak boleh terbaca sebagai over budget.
     */
    public const TOLERANCE = 0.0001;

    /** @return array<int,string> */
    public static function all(): array
    {
        return [self::ON_BUDGET, self::UNDER_BUDGET, self::OVER_BUDGET, self::NO_BUDGET, self::NO_ACTUAL];
    }

    /**
     * @param  string  $direction  BudgetDirection::REVENUE|EXPENSE
     */
    public static function resolve(float $budgetAmount, float $actualAmount, string $direction): string
    {
        $hasBudget = abs($budgetAmount) > self::TOLERANCE;
        $hasActual = abs($actualAmount) > self::TOLERANCE;

        if (! $hasBudget) {
            // Tanpa anggaran, "hemat" tidak punya arti — yang informatif adalah
            // ada tidaknya realisasi yang tak dianggarkan.
            return $hasActual ? self::NO_BUDGET : self::NO_ACTUAL;
        }

        if (! $hasActual) {
            return self::NO_ACTUAL;
        }

        $difference = $actualAmount - $budgetAmount;

        if (abs($difference) <= self::TOLERANCE) {
            return self::ON_BUDGET;
        }

        // Beban: actual > anggaran = boros. Pendapatan: actual > anggaran =
        // target terlampaui. Tandanya sudah dinormalkan di BudgetActualService,
        // yang tersisa hanya membalik makna favorable-nya.
        if ($direction === BudgetDirection::REVENUE) {
            return $difference > 0 ? self::UNDER_BUDGET : self::OVER_BUDGET;
        }

        return $difference > 0 ? self::OVER_BUDGET : self::UNDER_BUDGET;
    }

    /**
     * Utilization selalu `actual / budget`. Anggaran 0 → null: jangan bagi nol,
     * jangan kembalikan INF, dan jangan kembalikan 0 (0% terbaca "belum
     * terpakai", padahal artinya "tidak dianggarkan").
     */
    public static function utilizationPct(float $budgetAmount, float $actualAmount): ?float
    {
        if (abs($budgetAmount) <= self::TOLERANCE) {
            return null;
        }

        return round(($actualAmount / $budgetAmount) * 100, 2);
    }

    /**
     * Variance ditulis supaya positif selalu berarti favorable:
     * beban `budget − actual` (sisa), pendapatan `actual − budget` (kelebihan).
     */
    public static function variance(float $budgetAmount, float $actualAmount, string $direction): float
    {
        return $direction === BudgetDirection::REVENUE
            ? $actualAmount - $budgetAmount
            : $budgetAmount - $actualAmount;
    }

    public static function variancePct(float $budgetAmount, float $variance): ?float
    {
        if (abs($budgetAmount) <= self::TOLERANCE) {
            return null;
        }

        return round(($variance / $budgetAmount) * 100, 2);
    }
}
