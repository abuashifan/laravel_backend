<?php

namespace App\Support\Revision;

class TransactionRevisionAction
{
    public const EDIT = 'edit';
    public const VOID = 'void';
    public const CORRECTION = 'correction';
    public const SYSTEM_REBUILD = 'system_rebuild';

    public static function all(): array
    {
        return [
            self::EDIT,
            self::VOID,
            self::CORRECTION,
            self::SYSTEM_REBUILD,
        ];
    }

    public static function exists(string $action): bool
    {
        return in_array($action, self::all(), true);
    }
}

