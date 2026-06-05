<?php

namespace App\Contracts\Transactions;

use App\Support\Transaction\DependencyCheckResult;

interface TransactionDependencyChecker
{
    public function check(mixed $transaction, string $action, string $module): DependencyCheckResult;

    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool;

    public function blockingReasons(mixed $transaction, string $action, string $module): array;
}
