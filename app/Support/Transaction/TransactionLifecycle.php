<?php

namespace App\Support\Transaction;

class TransactionLifecycle
{
    public static function isValidStatus(string $status): bool
    {
        return in_array($status, TransactionStatus::transactionStatuses(), true);
    }

    public static function isValidEffectStatus(string $status): bool
    {
        return in_array($status, TransactionStatus::effectStatuses(), true);
    }

    public static function isVisible(string $status): bool
    {
        return in_array($status, (array) config('transaction_lifecycle.visible_statuses', []), true);
    }

    public static function isEditableStatus(string $status): bool
    {
        return in_array($status, (array) config('transaction_lifecycle.editable_statuses', []), true);
    }

    public static function isVoidableStatus(string $status): bool
    {
        return in_array($status, (array) config('transaction_lifecycle.voidable_statuses', []), true);
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, (array) config('transaction_lifecycle.terminal_statuses', []), true);
    }

    public static function isReportableJournalStatus(string $status, bool $isObsolete = false): bool
    {
        if ($isObsolete) {
            return false;
        }

        return in_array($status, (array) config('transaction_lifecycle.reportable_journal_statuses', []), true);
    }
}

