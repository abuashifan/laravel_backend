<?php

namespace App\Contracts\Transactions;

use App\Support\Transaction\TransactionPolicyResult;

interface TransactionDateGuard
{
    public function check(?string $transactionDate, string $action, string $module): TransactionPolicyResult;
}

