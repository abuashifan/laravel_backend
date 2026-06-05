<?php

namespace App\Support\Transaction;

class TransactionAction
{
    public const CREATE = 'create';
    public const EDIT = 'edit';
    public const VOID = 'void';
    public const APPROVE = 'approve';
    public const POST = 'post';
    public const VIEW = 'view';

    public static function all(): array
    {
        return [
            self::CREATE,
            self::EDIT,
            self::VOID,
            self::APPROVE,
            self::POST,
            self::VIEW,
        ];
    }
}

