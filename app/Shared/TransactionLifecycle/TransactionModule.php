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

    public static function permissionFor(string $module, string $action, ?string $feature = null): string
    {
        $module = trim($module);
        $action = trim($action);

        if (! in_array($module, self::all(), true)) {
            throw new InvalidArgumentException('UNKNOWN_TRANSACTION_MODULE');
        }

        if (! in_array($action, TransactionAction::all(), true)) {
            throw new InvalidArgumentException('UNKNOWN_TRANSACTION_ACTION');
        }

        $prefix = $module === self::JOURNAL ? 'accounting.journals' : $module.'.'.$feature;
        $mappedAction = match ($action) {
            TransactionAction::VOID => 'delete',
            TransactionAction::POST => 'approve',
            default => $action,
        };
        $key = $prefix.'.'.$mappedAction;
        if (! in_array($key, (array) config('permissions.matrix_keys', []), true)) {
            throw new InvalidArgumentException('TRANSACTION_FEATURE_REQUIRED');
        }

        return $key;
    }
}
