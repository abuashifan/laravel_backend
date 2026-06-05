<?php

namespace App\Support\OpeningBalance;

class OpeningBalanceLine
{
    private function __construct(
        public readonly int|string|null $accountId,
        public readonly ?string $accountCode,
        public readonly ?string $accountName,
        public readonly ?string $accountType,
        public readonly float|int|string $debit,
        public readonly float|int|string $credit,
        public readonly ?string $description,
        public readonly array $metadata,
    ) {
    }

    public static function make(
        int|string|null $accountId,
        ?string $accountCode,
        ?string $accountName,
        ?string $accountType,
        float|int|string $debit = 0,
        float|int|string $credit = 0,
        ?string $description = null,
        array $metadata = []
    ): self {
        return new self(
            $accountId,
            $accountCode,
            $accountName,
            $accountType,
            $debit,
            $credit,
            $description,
            $metadata
        );
    }

    public function toArray(): array
    {
        return [
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'account_type' => $this->accountType,
            'debit' => $this->debitAmount(),
            'credit' => $this->creditAmount(),
            'description' => $this->description,
            'metadata' => $this->metadata,
        ];
    }

    public function debitAmount(): float
    {
        return (float) $this->debit;
    }

    public function creditAmount(): float
    {
        return (float) $this->credit;
    }

    public function isDebit(): bool
    {
        return $this->debitAmount() > 0 && $this->creditAmount() == 0.0;
    }

    public function isCredit(): bool
    {
        return $this->creditAmount() > 0 && $this->debitAmount() == 0.0;
    }

    public function isZero(): bool
    {
        return $this->debitAmount() == 0.0 && $this->creditAmount() == 0.0;
    }

    public function hasBothDebitAndCredit(): bool
    {
        return $this->debitAmount() > 0 && $this->creditAmount() > 0;
    }
}

