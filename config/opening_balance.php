<?php

return [
    'source_type' => 'opening_balance',

    'source_module' => 'opening_balance',

    'document_type' => 'opening_balance',

    'default_document_prefix' => 'OB',

    'default_status' => 'posted',

    'require_balanced_entry' => true,

    'allow_unbalanced_opening_balance' => false,

    'allow_nominal_accounts_opening_balance' => false,

    'real_account_types' => [
        'asset',
        'liability',
        'equity',
    ],

    'nominal_account_types' => [
        'revenue',
        'expense',
    ],

    'normal_balances' => [
        'asset' => 'debit',
        'expense' => 'debit',
        'liability' => 'credit',
        'equity' => 'credit',
        'revenue' => 'credit',
    ],
];

