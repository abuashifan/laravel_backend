<?php

namespace App\Shared\TransactionLifecycle\Checkers;

class JournalTransactionDependencyChecker extends BaseTransactionDependencyChecker
{
    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool
    {
        // TODO (Phase Journal):
        // - system-generated journals should be edited from source transaction
        // - linked adjustment/reversal journals
        // - fiscal year closed handled by Phase 4F/date guard
        return false;
    }

    public function blockingReasons(mixed $transaction, string $action, string $module): array
    {
        return [];
    }
}
