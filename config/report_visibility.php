<?php

return [
    'transaction_visible_statuses' => [
        'draft',
        'approved',
        'posted',
    ],

    'transaction_hidden_statuses' => [
        'void',
    ],

    'reportable_transaction_statuses' => [
        'posted',
    ],

    'reportable_journal_statuses' => [
        'posted',
    ],

    'excluded_report_statuses' => [
        'draft',
        'approved',
        'void',
    ],

    'audit_visible_statuses' => [
        'draft',
        'approved',
        'posted',
        'void',
    ],

    'default_hide_voided_transactions' => true,

    'exclude_obsolete_from_reports' => true,

    'closed_fiscal_year_visible' => true,

    'closed_fiscal_year_read_only' => true,
];

