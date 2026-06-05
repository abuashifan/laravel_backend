<?php

namespace App\Support\Audit;

class AuditResult
{
    public const SUCCESS = 'success';
    public const FAILED = 'failed';
    public const DENIED = 'denied';
    public const WARNING = 'warning';

    public static function all(): array
    {
        return [
            self::SUCCESS,
            self::FAILED,
            self::DENIED,
            self::WARNING,
        ];
    }

    public static function exists(string $result): bool
    {
        return in_array($result, self::all(), true);
    }
}

