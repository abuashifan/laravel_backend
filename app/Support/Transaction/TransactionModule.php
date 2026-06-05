<?php

namespace App\Support\Transaction;

use InvalidArgumentException;

class TransactionModule
{
    public const JOURNAL = 'journal';
    public const SALES = 'sales';
    public const PURCHASE = 'purchase';
    public const CASH_BANK = 'cash_bank';
    public const INVENTORY = 'inventory';
    public const MASTER_DATA = 'master_data';

    public static function all(): array
    {
        return [
            self::JOURNAL,
            self::SALES,
            self::PURCHASE,
            self::CASH_BANK,
            self::INVENTORY,
            self::MASTER_DATA,
        ];
    }

    public static function permissionFor(string $module, string $action): string
    {
        $module = trim($module);
        $action = trim($action);

        if (! in_array($module, self::all(), true)) {
            throw new InvalidArgumentException('UNKNOWN_TRANSACTION_MODULE');
        }

        if (! in_array($action, TransactionAction::all(), true)) {
            throw new InvalidArgumentException('UNKNOWN_TRANSACTION_ACTION');
        }

        // inventory uses manage for create/edit/void for now (granular adjustments later)
        if ($module === self::INVENTORY && in_array($action, [TransactionAction::CREATE, TransactionAction::EDIT, TransactionAction::VOID], true)) {
            return 'inventory.manage';
        }

        return $module.'.'.$action;
    }
}

