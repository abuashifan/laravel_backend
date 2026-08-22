<?php

namespace App\Shared\TransactionLifecycle;

use App\Shared\TransactionLifecycle\Contracts\TransactionDateGuard;

class NoopTransactionDateGuard implements TransactionDateGuard
{
    public function check(?string $transactionDate, string $action, string $module): TransactionPolicyResult
    {
        return TransactionPolicyResult::allow();
    }
}
