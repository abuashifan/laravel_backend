<?php

namespace App\Support\OpeningBalance;

class OpeningBalanceType
{
    public const STANDARD = 'standard';
    public const MIGRATION = 'migration';
    public const CORRECTION = 'correction';

    public static function all(): array
    {
        return [
            self::STANDARD,
            self::MIGRATION,
            self::CORRECTION,
        ];
    }

    public static function exists(string $type): bool
    {
        return in_array($type, self::all(), true);
    }
}

