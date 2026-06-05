<?php

namespace App\Support\Transaction;

class TransactionStatus
{
    public const DRAFT = 'draft';
    public const APPROVED = 'approved';
    public const POSTED = 'posted';
    public const VOID = 'void';

    // Effect/system-only status (not a main transaction status)
    public const OBSOLETE = 'obsolete';

    public static function transactionStatuses(): array
    {
        return (array) config('transaction_lifecycle.statuses', [
            self::DRAFT,
            self::APPROVED,
            self::POSTED,
            self::VOID,
        ]);
    }

    public static function effectStatuses(): array
    {
        return (array) config('transaction_lifecycle.effect_statuses', [
            self::DRAFT,
            self::POSTED,
            self::VOID,
            self::OBSOLETE,
        ]);
    }
}

