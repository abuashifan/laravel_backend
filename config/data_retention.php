<?php

return [
    'default_policy' => [
        'void_transaction_retention_days' => null,
        'auto_archive_voided_transactions' => false,
        'archive_voided_after_days' => 365,

        'active_data_retention_years' => 5,
        'auto_archive_closed_fiscal_years' => false,
        'archive_closed_fiscal_year_after_years' => 5,

        'allow_purge_archived_data' => false,
        'purge_archived_after_years' => null,

        'audit_log_retention_years' => null,
        'revision_history_retention_years' => null,
    ],

    'rules' => [
        'hard_delete_active_transactions' => false,
        'purge_requires_backup' => true,
        'purge_requires_audit_log' => true,
        'purge_requires_preview' => true,
        'archive_requires_closed_fiscal_year' => true,
        'archive_must_not_affect_reports' => true,
    ],

    'retention_scopes' => [
        'transaction',
        'journal_effect',
        'audit_log',
        'revision',
        'import_batch',
    ],
];

