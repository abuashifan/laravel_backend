<?php

namespace App\Services\Transactions\Checkers;

class CashBankTransactionDependencyChecker extends BaseTransactionDependencyChecker
{
    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool
    {
        // TODO (Phase Cash/Bank):
        // - reconciliation
        // - allocation to invoices
        // - transfer pair transaction
        // - fiscal year closed handled by Phase 4F/date guard
        return false;
    }

    public function blockingReasons(mixed $transaction, string $action, string $module): array
    {
        return [];
    }
}

