<?php

namespace App\Services\Transactions\Checkers;

use App\Contracts\Transactions\TransactionDependencyChecker;
use App\Support\Transaction\DependencyCheckResult;

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

