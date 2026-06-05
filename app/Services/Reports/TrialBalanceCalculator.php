<?php

namespace App\Services\Reports;

use InvalidArgumentException;

class TrialBalanceCalculator
{
    private const TOLERANCE = 0.0001;

    public function balanceFromDebitCredit(float|string|int $debit, float|string|int $credit, string $normalBalance): float
    {
        $debit = (float) $debit;
        $credit = (float) $credit;

        if ($normalBalance === 'debit') {
            return $debit - $credit;
        }

        if ($normalBalance === 'credit') {
            return $credit - $debit;
        }

        throw new InvalidArgumentException('Unknown normal_balance: '.$normalBalance);
    }

    /**
     * @return array{debit:float,credit:float}
     */
    public function splitBalance(float $balance, string $normalBalance): array
    {
        if (! in_array($normalBalance, ['debit', 'credit'], true)) {
            throw new InvalidArgumentException('Unknown normal_balance: '.$normalBalance);
        }

        // Presentation rule:
        // - positive balance goes to the account's normal side
        // - negative balance goes to the opposite side
        $abs = abs($balance);

        if ($abs < self::TOLERANCE) {
            return ['debit' => 0.0, 'credit' => 0.0];
        }

        if ($balance >= 0) {
            return $normalBalance === 'debit'
                ? ['debit' => $abs, 'credit' => 0.0]
                : ['debit' => 0.0, 'credit' => $abs];
        }

        // negative -> opposite side
        return $normalBalance === 'debit'
            ? ['debit' => 0.0, 'credit' => $abs]
            : ['debit' => $abs, 'credit' => 0.0];
    }

    /**
     * @return array{ending_balance:float,ending_debit:float,ending_credit:float,opening_balance:float,opening_debit:float,opening_credit:float}
     */
    public function calculateEnding(
        float $openingDebit,
        float $openingCredit,
        float $periodDebit,
        float $periodCredit,
        string $normalBalance
    ): array {
        $openingBalance = $this->balanceFromDebitCredit($openingDebit, $openingCredit, $normalBalance);
        $movement = $this->balanceFromDebitCredit($periodDebit, $periodCredit, $normalBalance);
        $endingBalance = $openingBalance + $movement;

        $openingSplit = $this->splitBalance($openingBalance, $normalBalance);
        $endingSplit = $this->splitBalance($endingBalance, $normalBalance);

        return [
            'opening_balance' => $openingBalance,
            'opening_debit' => (float) $openingSplit['debit'],
            'opening_credit' => (float) $openingSplit['credit'],
            'ending_balance' => $endingBalance,
            'ending_debit' => (float) $endingSplit['debit'],
            'ending_credit' => (float) $endingSplit['credit'],
        ];
    }

    public function totals(array $accounts): array
    {
        $sum = [
            'opening_debit' => 0.0,
            'opening_credit' => 0.0,
            'period_debit' => 0.0,
            'period_credit' => 0.0,
            'ending_debit' => 0.0,
            'ending_credit' => 0.0,
        ];

        foreach ($accounts as $row) {
            $sum['opening_debit'] += (float) ($row['opening_debit'] ?? 0);
            $sum['opening_credit'] += (float) ($row['opening_credit'] ?? 0);
            $sum['period_debit'] += (float) ($row['period_debit'] ?? 0);
            $sum['period_credit'] += (float) ($row['period_credit'] ?? 0);
            $sum['ending_debit'] += (float) ($row['ending_debit'] ?? 0);
            $sum['ending_credit'] += (float) ($row['ending_credit'] ?? 0);
        }

        $diff = $this->difference((float) $sum['ending_debit'], (float) $sum['ending_credit']);

        return [
            ...$sum,
            'difference' => $diff,
            'is_balanced' => $this->isBalanced((float) $sum['ending_debit'], (float) $sum['ending_credit']),
        ];
    }

    public function isBalanced(float $totalDebit, float $totalCredit): bool
    {
        return abs($totalDebit - $totalCredit) <= self::TOLERANCE;
    }

    public function difference(float $totalDebit, float $totalCredit): float
    {
        return $totalDebit - $totalCredit;
    }
}

