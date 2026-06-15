<?php

namespace App\Support\SourceLink;

class SourceModule
{
    public const JOURNAL = 'journal';
    public const SALES = 'sales';
    public const PURCHASE = 'purchase';
    public const CASH_BANK = 'cash_bank';
    public const INVENTORY = 'inventory';
    public const FIXED_ASSETS = 'fixed_assets';
    public const PERIOD_END = 'period_end';
    public const CLOSING = 'closing';
    public const OPENING_BALANCE = 'opening_balance';
    public const IMPORT = 'import';
    public const SYSTEM = 'system';

    public static function all(): array
    {
        return (array) config('source_links.source_modules', []);
    }

    public static function exists(string $sourceModule): bool
    {
        return in_array($sourceModule, self::all(), true);
    }
}
