<?php

namespace App\Support\Reports;

class ReportVisibilityMode
{
    public const NORMAL = 'normal';
    public const WITH_VOID = 'with_void';
    public const AUDIT = 'audit';
    public const REPORT = 'report';
    public const REVISION = 'revision';

    public static function all(): array
    {
        return [
            self::NORMAL,
            self::WITH_VOID,
            self::AUDIT,
            self::REPORT,
            self::REVISION,
        ];
    }

    public static function exists(string $mode): bool
    {
        return in_array($mode, self::all(), true);
    }
}

