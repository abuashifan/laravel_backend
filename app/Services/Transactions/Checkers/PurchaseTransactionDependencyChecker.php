<?php

namespace App\Services\Transactions\Checkers;

class PurchaseTransactionDependencyChecker extends BaseTransactionDependencyChecker
{
    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool
    {
        // TODO (Phase Purchase):
        // - purchase payments
        // - purchase returns / debit notes
        // - bank reconciliation
        // - stock movements used by costing
        // - fiscal year closed handled by Phase 4F/date guard
        return false;
    }

    public function blockingReasons(mixed $transaction, string $action, string $module): array
    {
        return [];
    }
}

