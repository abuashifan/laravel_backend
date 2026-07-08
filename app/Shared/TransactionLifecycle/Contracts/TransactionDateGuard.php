<?php

namespace App\Shared\TransactionLifecycle\Contracts;

use App\Shared\TransactionLifecycle\TransactionPolicyResult;

interface TransactionDateGuard
{
    public function check(?string $transactionDate, string $action, string $module): TransactionPolicyResult;
}

