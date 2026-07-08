<?php

namespace App\Shared\Reports\Data;

class LedgerAccountSummaryData
{
    public function __construct(
        public array $account,
        public array $openingBalance,
        public array $periodTotals,
        public float $endingBalance,
    ) {
    }

    public function toArray(): array
    {
        return [
            'account' => $this->account,
            'opening_balance' => $this->openingBalance,
            'period_totals' => $this->periodTotals,
            'ending_balance' => $this->endingBalance,
        ];
    }
}

