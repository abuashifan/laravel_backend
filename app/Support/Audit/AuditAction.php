<?php

namespace App\Support\Audit;

class AuditAction
{
    public const VIEW = 'view';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const EDIT = 'edit';
    public const VOID = 'void';
    public const APPROVE = 'approve';
    public const POST = 'post';
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const SWITCH = 'switch';
    public const EXPORT = 'export';
    public const IMPORT = 'import';
    public const CLOSE = 'close';
    public const REOPEN = 'reopen';
    public const DENY = 'deny';
    public const SYSTEM = 'system';

    public static function all(): array
    {
        return [
            self::VIEW,
            self::CREATE,
            self::UPDATE,
            self::EDIT,
            self::VOID,
            self::APPROVE,
            self::POST,
            self::LOGIN,
            self::LOGOUT,
            self::SWITCH,
            self::EXPORT,
            self::IMPORT,
            self::CLOSE,
            self::REOPEN,
            self::DENY,
            self::SYSTEM,
        ];
    }

    public static function exists(string $action): bool
    {
        return in_array($action, self::all(), true);
    }
}

