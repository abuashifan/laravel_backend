<?php

return [
    'codes' => [
        'VALIDATION_ERROR' => 'The given data was invalid.',
        'UNAUTHENTICATED' => 'Unauthenticated.',
        'FORBIDDEN' => 'Forbidden.',
        'PERMISSION_DENIED' => 'You do not have permission to perform this action.',
        'COMPANY_ACCESS_DENIED' => 'You do not have access to this company.',
        'COMPANY_NOT_FOUND' => 'Company tidak ditemukan.',
        'X_COMPANY_ID_REQUIRED' => 'X-Company-ID wajib dikirim.',
        'TENANT_DATABASE_NOT_ACTIVE' => 'Tenant database is not active.',

        'TRANSACTION_HAS_DEPENDENCY' => 'Transaction has related records and cannot be modified.',
        'TRANSACTION_STATUS_NOT_EDITABLE' => 'Transaction status is not editable.',
        'TRANSACTION_STATUS_NOT_VOIDABLE' => 'Transaction status is not voidable.',
        'TRANSACTION_ALREADY_VOID' => 'Transaction is already void.',
        'TRANSACTION_ALREADY_POSTED' => 'Transaction is already posted.',
        'COMPANY_SETTING_EDIT_DISABLED' => 'Company setting disallows editing transactions.',
        'COMPANY_SETTING_EDIT_POSTED_DISABLED' => 'Company setting disallows editing posted transactions.',
        'COMPANY_SETTING_VOID_DISABLED' => 'Company setting disallows voiding transactions.',

        'FISCAL_YEAR_CLOSED' => 'Fiscal year is closed. Transaction is read-only.',
        'ACCOUNTING_PERIOD_CLOSED' => 'Accounting period is closed. Transaction is read-only.',
        'TRANSACTION_DATE_OUTSIDE_ACTIVE_FISCAL_YEAR' => 'Transaction date is outside the active fiscal year.',
        'PREVIOUS_FISCAL_YEAR_NOT_CLOSED' => 'Previous fiscal year must be closed before entering transactions in the next fiscal year.',
        'TRANSACTION_DATE_INVALID' => 'Transaction date is invalid.',

        'BACKDATED_TRANSACTION_NOT_ALLOWED' => 'Backdated transaction is not allowed.',
        'BACKDATED_TRANSACTION_TOO_FAR' => 'Backdated transaction is too far.',
        'FUTURE_TRANSACTION_NOT_ALLOWED' => 'Future transaction is not allowed.',
        'FUTURE_TRANSACTION_TOO_FAR' => 'Future transaction is too far.',

        'DOCUMENT_NUMBER_DUPLICATE' => 'Document number already exists.',
        'DOCUMENT_NUMBERING_INACTIVE' => 'Document numbering setting is inactive.',
        'UNKNOWN_DOCUMENT_TYPE' => 'Unknown document type.',

        'ACCOUNT_MAPPING_MISSING' => 'Required account mapping is missing.',
        'OPENING_BALANCE_UNBALANCED' => 'Opening balance must be balanced.',

        'SYSTEM_GENERATED_READ_ONLY' => 'System-generated record cannot be modified directly.',
        'EDIT_REASON_REQUIRED' => 'Edit reason is required.',
        'JOURNAL_REQUIRES_APPROVAL' => 'Journal must be approved before posting.',

        'UNKNOWN_ERROR' => 'Unknown error.',
    ],

    'warnings' => [
        'FUTURE_TRANSACTION_DATE_WARNING' => 'Transaction date is in the future.',
        'DIFFERENT_PERIOD_DATE_WARNING' => 'Transaction date is in a different period.',
        'BACKDATED_TRANSACTION_WARNING' => 'Transaction date is backdated.',
    ],
];
