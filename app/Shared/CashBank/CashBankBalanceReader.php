<?php

namespace App\Shared\CashBank;

/** Read-only boundary for payment modules that need a cash/bank balance preview. */
interface CashBankBalanceReader
{
    public function accountBalance(int $accountId, string $asOf, ?int $excludedJournalId = null): array;
}
