<?php

namespace App\Services\Reports;

use InvalidArgumentException;

class LedgerBalanceCalculator
{
    public function signedAmount(float|string|int $debit, float|string|int $credit, string $normalBalance): float
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

    public function openingBalance(float|string|int $debitTotal, float|string|int $creditTotal, string $normalBalance): float
    {
        return $this->signedAmount($debitTotal, $creditTotal, $normalBalance);
    }

    public function endingBalance(float $openingBalance, float|string|int $periodDebit, float|string|int $periodCredit, string $normalBalance): float
    {
        return $openingBalance + $this->signedAmount($periodDebit, $periodCredit, $normalBalance);
    }

    public function runningBalance(float $currentBalance, float|string|int $debit, float|string|int $credit, string $normalBalance): float
    {
        return $currentBalance + $this->signedAmount($debit, $credit, $normalBalance);
    }
}

