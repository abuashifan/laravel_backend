<?php

namespace App\Shared\Api;

class ApiErrorCode
{
    public const VALIDATION_ERROR = 'VALIDATION_ERROR';

    public const UNAUTHENTICATED = 'UNAUTHENTICATED';

    public const FORBIDDEN = 'FORBIDDEN';

    public const PERMISSION_DENIED = 'PERMISSION_DENIED';

    /**
     * Ditolak paket, bukan izin. Jalan keluarnya berbeda dari PERMISSION_DENIED:
     * yang ini hanya bisa dibuka penyedia aplikasi, bukan admin perusahaan.
     */
    public const FEATURE_NOT_IN_PLAN = 'FEATURE_NOT_IN_PLAN';

    /**
     * Langganan client sudah lewat tenggang 7 hari — kunci penuh, login ditolak
     * (Fase 3, skema tier). Berbeda dari FEATURE_NOT_IN_PLAN: ini menahan
     * SELURUH akses, bukan satu kemampuan, dan jalan keluarnya perpanjangan
     * atau admin membuka kunci — bukan naik tier.
     */
    public const SUBSCRIPTION_EXPIRED = 'SUBSCRIPTION_EXPIRED';

    /**
     * Kuota penyimpanan perusahaan sudah penuh (Fase 4, skema tier). Menahan
     * penambahan data baru — bukan mengunci akses baca, sejalan dengan aturan
     * yang berlaku di seluruh rencana ini.
     */
    public const STORAGE_QUOTA_EXCEEDED = 'STORAGE_QUOTA_EXCEEDED';

    public const COMPANY_ACCESS_DENIED = 'COMPANY_ACCESS_DENIED';

    public const COMPANY_NOT_FOUND = 'COMPANY_NOT_FOUND';

    public const X_COMPANY_ID_REQUIRED = 'X_COMPANY_ID_REQUIRED';

    public const TENANT_DATABASE_NOT_ACTIVE = 'TENANT_DATABASE_NOT_ACTIVE';

    public const TRANSACTION_HAS_DEPENDENCY = 'TRANSACTION_HAS_DEPENDENCY';

    public const TRANSACTION_STATUS_NOT_EDITABLE = 'TRANSACTION_STATUS_NOT_EDITABLE';

    public const TRANSACTION_STATUS_NOT_VOIDABLE = 'TRANSACTION_STATUS_NOT_VOIDABLE';

    public const TRANSACTION_ALREADY_VOID = 'TRANSACTION_ALREADY_VOID';

    public const TRANSACTION_ALREADY_POSTED = 'TRANSACTION_ALREADY_POSTED';

    public const COMPANY_SETTING_EDIT_DISABLED = 'COMPANY_SETTING_EDIT_DISABLED';

    public const COMPANY_SETTING_EDIT_POSTED_DISABLED = 'COMPANY_SETTING_EDIT_POSTED_DISABLED';

    public const COMPANY_SETTING_VOID_DISABLED = 'COMPANY_SETTING_VOID_DISABLED';

    public const FISCAL_YEAR_CLOSED = 'FISCAL_YEAR_CLOSED';

    public const ACCOUNTING_PERIOD_CLOSED = 'ACCOUNTING_PERIOD_CLOSED';

    public const TRANSACTION_DATE_OUTSIDE_ACTIVE_FISCAL_YEAR = 'TRANSACTION_DATE_OUTSIDE_ACTIVE_FISCAL_YEAR';

    public const PREVIOUS_FISCAL_YEAR_NOT_CLOSED = 'PREVIOUS_FISCAL_YEAR_NOT_CLOSED';

    public const TRANSACTION_DATE_INVALID = 'TRANSACTION_DATE_INVALID';

    public const BACKDATED_TRANSACTION_NOT_ALLOWED = 'BACKDATED_TRANSACTION_NOT_ALLOWED';

    public const BACKDATED_TRANSACTION_TOO_FAR = 'BACKDATED_TRANSACTION_TOO_FAR';

    public const FUTURE_TRANSACTION_NOT_ALLOWED = 'FUTURE_TRANSACTION_NOT_ALLOWED';

    public const FUTURE_TRANSACTION_TOO_FAR = 'FUTURE_TRANSACTION_TOO_FAR';

    public const FUTURE_TRANSACTION_DATE_WARNING = 'FUTURE_TRANSACTION_DATE_WARNING';

    public const DIFFERENT_PERIOD_DATE_WARNING = 'DIFFERENT_PERIOD_DATE_WARNING';

    public const BACKDATED_TRANSACTION_WARNING = 'BACKDATED_TRANSACTION_WARNING';

    public const DOCUMENT_NUMBER_DUPLICATE = 'DOCUMENT_NUMBER_DUPLICATE';

    public const DOCUMENT_NUMBERING_INACTIVE = 'DOCUMENT_NUMBERING_INACTIVE';

    public const UNKNOWN_DOCUMENT_TYPE = 'UNKNOWN_DOCUMENT_TYPE';

    public const ACCOUNT_MAPPING_MISSING = 'ACCOUNT_MAPPING_MISSING';

    public const OPENING_BALANCE_UNBALANCED = 'OPENING_BALANCE_UNBALANCED';

    public const SYSTEM_GENERATED_READ_ONLY = 'SYSTEM_GENERATED_READ_ONLY';

    public const EDIT_REASON_REQUIRED = 'EDIT_REASON_REQUIRED';

    public const JOURNAL_REQUIRES_APPROVAL = 'JOURNAL_REQUIRES_APPROVAL';

    public const IMPORT_FILE_INVALID = 'IMPORT_FILE_INVALID';

    public const IMPORT_ACTIVE_BATCH_EXISTS = 'IMPORT_ACTIVE_BATCH_EXISTS';

    public const IMPORT_FILE_DUPLICATE = 'IMPORT_FILE_DUPLICATE';

    public const UNKNOWN_ERROR = 'UNKNOWN_ERROR';

    public static function all(): array
    {
        return array_keys((array) config('api_errors.codes', []));
    }

    public static function exists(string $code): bool
    {
        return array_key_exists($code, (array) config('api_errors.codes', []))
            || array_key_exists($code, (array) config('api_errors.warnings', []));
    }

    public static function message(string $code): string
    {
        $codes = (array) config('api_errors.codes', []);
        $warnings = (array) config('api_errors.warnings', []);

        return (string) ($codes[$code] ?? $warnings[$code] ?? $codes[self::UNKNOWN_ERROR] ?? 'Unknown error.');
    }

    public static function isWarning(string $code): bool
    {
        return array_key_exists($code, (array) config('api_errors.warnings', []));
    }
}
