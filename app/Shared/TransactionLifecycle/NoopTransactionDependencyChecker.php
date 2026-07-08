<?php

namespace App\Shared\TransactionLifecycle;

use App\Shared\TransactionLifecycle\Contracts\TransactionDependencyChecker;
use App\Shared\TransactionLifecycle\DependencyCheckResult;

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

