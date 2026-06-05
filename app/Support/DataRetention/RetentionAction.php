<?php

namespace App\Support\DataRetention;

class RetentionAction
{
    public const KEEP = 'keep';
    public const HIDE = 'hide';
    public const ARCHIVE_ELIGIBLE = 'archive_eligible';
    public const ARCHIVE = 'archive';
    public const PURGE_ELIGIBLE = 'purge_eligible';
    public const PURGE = 'purge';
    public const BLOCK = 'block';

    public static function all(): array
    {
        return [
            self::KEEP,
            self::HIDE,
            self::ARCHIVE_ELIGIBLE,
            self::ARCHIVE,
            self::PURGE_ELIGIBLE,
            self::PURGE,
            self::BLOCK,
        ];
    }

    public static function exists(string $action): bool
    {
        return in_array($action, self::all(), true);
    }
}

