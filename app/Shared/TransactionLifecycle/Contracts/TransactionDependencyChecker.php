<?php

namespace App\Shared\TransactionLifecycle\Contracts;

use App\Shared\TransactionLifecycle\DependencyCheckResult;

interface TransactionDependencyChecker
{
    public function check(mixed $transaction, string $action, string $module): DependencyCheckResult;

    public function hasBlockingDependencies(mixed $transaction, string $action, string $module): bool;

    public function blockingReasons(mixed $transaction, string $action, string $module): array;
}
