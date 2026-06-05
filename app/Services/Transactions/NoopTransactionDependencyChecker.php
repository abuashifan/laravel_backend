<?php

namespace App\Services\Transactions;

use App\Contracts\Transactions\TransactionDependencyChecker;
use App\Support\Transaction\DependencyCheckResult;

class NoopTransactionDependencyChecker implements TransactionDependencyChecker
{
    public function check(mixed $transaction, string $action, string $module): DependencyCheckResult
    {
        return DependencyCheckResult::clear();
    }

    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool
    {
        return false;
    }

    public function blockingReasons(mixed $transaction, string $action, string $module): array
    {
        return [];
    }
}

