<?php

namespace App\Support\Inventory;

class InventoryMovementType
{
    public const PURCHASE_IN = 'purchase_in';
    public const PURCHASE_RETURN_OUT = 'purchase_return_out';
    public const SALES_OUT = 'sales_out';
    public const SALES_RETURN_IN = 'sales_return_in';
    public const ADJUSTMENT_IN = 'adjustment_in';
    public const ADJUSTMENT_OUT = 'adjustment_out';
    public const OPNAME_IN = 'opname_in';
    public const OPNAME_OUT = 'opname_out';
    public const TRANSFER_IN = 'transfer_in';
    public const TRANSFER_OUT = 'transfer_out';
    public const OPENING_STOCK = 'opening_stock';

    public static function all(): array
    {
        return (array) config('inventory.movement_types', []);
    }

    public static function exists(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}

