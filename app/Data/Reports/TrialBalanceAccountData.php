<?php

namespace App\Data\Reports;

class TrialBalanceAccountData
{
    public function __construct(
        public int|string $accountId,
        public string $accountCode,
        public string $accountName,
        public string $accountType,
        public string $normalBalance,
        public bool $isActive,
        public float $openingDebit,
        public float $openingCredit,
        public float $periodDebit,
        public float $periodCredit,
        public float $endingDebit,
        public float $endingCredit,
        public float $endingBalance,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            accountId: $data['account_id'],
            accountCode: (string) $data['account_code'],
            accountName: (string) $data['account_name'],
            accountType: (string) $data['account_type'],
            normalBalance: (string) $data['normal_balance'],
            isActive: (bool) $data['is_active'],
            openingDebit: (float) $data['opening_debit'],
            openingCredit: (float) $data['opening_credit'],
            periodDebit: (float) $data['period_debit'],
            periodCredit: (float) $data['period_credit'],
            endingDebit: (float) $data['ending_debit'],
            endingCredit: (float) $data['ending_credit'],
            endingBalance: (float) $data['ending_balance'],
        );
    }

    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'account_type' => $this->accountType,
            'normal_balance' => $this->normalBalance,
            'is_active' => $this->isActive,
            'opening_debit' => $this->openingDebit,
            'opening_credit' => $this->openingCredit,
            'period_debit' => $this->periodDebit,
            'period_credit' => $this->periodCredit,
            'ending_debit' => $this->endingDebit,
            'ending_credit' => $this->endingCredit,
            'ending_balance' => $this->endingBalance,
        ];
    }
}

