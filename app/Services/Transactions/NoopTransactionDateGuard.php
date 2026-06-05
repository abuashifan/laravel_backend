<?php

namespace App\Services\Transactions;

use App\Contracts\Transactions\TransactionDateGuard;
use App\Support\Transaction\TransactionPolicyResult;

class NoopTransactionDateGuard implements TransactionDateGuard
{
    public function check(?string $transactionDate, string $action, string $module): TransactionPolicyResult
    {
        return TransactionPolicyResult::allow();
    }
}

