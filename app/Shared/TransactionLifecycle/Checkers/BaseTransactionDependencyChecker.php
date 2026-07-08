<?php

namespace App\Shared\TransactionLifecycle\Checkers;

use App\Shared\TransactionLifecycle\Contracts\TransactionDependencyChecker;
use App\Shared\TransactionLifecycle\DependencyCheckResult;

abstract class BaseTransactionDependencyChecker implements TransactionDependencyChecker
{
    public function check(mixed $transaction, string $action, string $module): DependencyCheckResult
    {
        if (! $this->hasBlockingDependencies($transaction, $action, $module)) {
            return DependencyCheckResult::clear();
        }

        return DependencyCheckResult::blocked(
            $this->blockingReasons($transaction, $action, $module)
        );
    }
}

