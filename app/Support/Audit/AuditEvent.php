<?php

namespace App\Support\Audit;

class AuditEvent
{
    // Auth/Central
    public const AUTH_LOGIN = 'auth.login';
    public const AUTH_LOGOUT = 'auth.logout';
    public const AUTH_LOGIN_FAILED = 'auth.login_failed';
    public const COMPANY_SWITCHED = 'company.switched';
    public const COMPANY_SETTING_UPDATED = 'company_setting.updated';
    public const PERMISSION_DENIED = 'permission.denied';

    // Settings
    public const SETTINGS_COMPANY_UPDATED = 'settings.company.updated';
    public const SETTINGS_MODULES_UPDATED = 'settings.modules.updated';

    // Journal
    public const JOURNAL_CREATED = 'journal.created';
    public const JOURNAL_UPDATED = 'journal.updated';
    public const JOURNAL_APPROVED = 'journal.approved';
    public const JOURNAL_POSTED = 'journal.posted';
    public const JOURNAL_VOIDED = 'journal.voided';

    // Sales
    public const SALES_INVOICE_CREATED = 'sales_invoice.created';
    public const SALES_INVOICE_UPDATED = 'sales_invoice.updated';
    public const SALES_INVOICE_POSTED = 'sales_invoice.posted';
    public const SALES_INVOICE_VOIDED = 'sales_invoice.voided';

    // Purchase
    public const PURCHASE_INVOICE_CREATED = 'purchase_invoice.created';
    public const PURCHASE_INVOICE_UPDATED = 'purchase_invoice.updated';
    public const PURCHASE_INVOICE_POSTED = 'purchase_invoice.posted';
    public const PURCHASE_INVOICE_VOIDED = 'purchase_invoice.voided';

    // Fiscal/Closing
    public const FISCAL_YEAR_CLOSING_STARTED = 'fiscal_year.closing_started';
    public const FISCAL_YEAR_CLOSED = 'fiscal_year.closed';
    public const CLOSING_JOURNAL_GENERATED = 'closing_journal.generated';
    public const OPENING_BALANCE_GENERATED = 'opening_balance.generated';

    // Generic
    public const RECORD_VIEWED = 'record.viewed';
    public const RECORD_CREATED = 'record.created';
    public const RECORD_UPDATED = 'record.updated';
    public const RECORD_VOIDED = 'record.voided';
    public const RECORD_EXPORTED = 'record.exported';

    public static function all(): array
    {
        return [
            self::AUTH_LOGIN,
            self::AUTH_LOGOUT,
            self::AUTH_LOGIN_FAILED,
            self::COMPANY_SWITCHED,
            self::COMPANY_SETTING_UPDATED,
            self::PERMISSION_DENIED,
            self::SETTINGS_COMPANY_UPDATED,
            self::SETTINGS_MODULES_UPDATED,
            self::JOURNAL_CREATED,
            self::JOURNAL_UPDATED,
            self::JOURNAL_APPROVED,
            self::JOURNAL_POSTED,
            self::JOURNAL_VOIDED,
            self::SALES_INVOICE_CREATED,
            self::SALES_INVOICE_UPDATED,
            self::SALES_INVOICE_POSTED,
            self::SALES_INVOICE_VOIDED,
            self::PURCHASE_INVOICE_CREATED,
            self::PURCHASE_INVOICE_UPDATED,
            self::PURCHASE_INVOICE_POSTED,
            self::PURCHASE_INVOICE_VOIDED,
            self::FISCAL_YEAR_CLOSING_STARTED,
            self::FISCAL_YEAR_CLOSED,
            self::CLOSING_JOURNAL_GENERATED,
            self::OPENING_BALANCE_GENERATED,
            self::RECORD_VIEWED,
            self::RECORD_CREATED,
            self::RECORD_UPDATED,
            self::RECORD_VOIDED,
            self::RECORD_EXPORTED,
        ];
    }

    public static function exists(string $event): bool
    {
        return in_array($event, self::all(), true);
    }
}

