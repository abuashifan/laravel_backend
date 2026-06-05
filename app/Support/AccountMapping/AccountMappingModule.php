<?php

namespace App\Support\AccountMapping;

class AccountMappingModule
{
    public const SALES = 'sales';
    public const PURCHASE = 'purchase';
    public const INVENTORY = 'inventory';
    public const CASH_BANK = 'cash_bank';
    public const JOURNAL = 'journal';
    public const OPENING_BALANCE = 'opening_balance';
    public const CLOSING = 'closing';
    public const TAX = 'tax';

    public static function all(): array
    {
        return (array) config('account_mappings.modules', []);
    }

    public static function exists(string $module): bool
    {
        return in_array($module, self::all(), true);
    }
}

