<?php

namespace App\Shared\TransactionLifecycle;

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

        if ($module !== self::JOURNAL) {
            throw new InvalidArgumentException('TRANSACTION_FEATURE_REQUIRED');
        }

        $legacy = 'journal.'.$action;

        // Dotted keys must be read from the map as an array, not config dot paths.
        return ((array) config('permission_renames'))[$legacy][0] ?? 'accounting.journals.'.$action;
    }
}
