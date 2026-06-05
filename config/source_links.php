<?php

return [
    'strict' => true,

    'source_types' => [
        'manual_journal',
        'opening_balance',
        'closing_entry',
        'sales_quotation',
        'sales_order',
        'delivery_order',
        'proforma_invoice',
        'sales_invoice',
        'billing_invoice',
        'sales_receipt',
        'sales_payment',
        'sales_return',
        'customer_deposit',
        'purchase_invoice',
        'purchase_payment',
        'purchase_return',
        'cash_receipt',
        'cash_payment',
        'bank_transfer',
        'stock_adjustment',
        'stock_movement',
        'stock_opname',
        'inventory_transfer',
        'import_batch',
        'system',
    ],

    'source_modules' => [
        'journal',
        'sales',
        'purchase',
        'cash_bank',
        'inventory',
        'closing',
        'opening_balance',
        'import',
        'system',
    ],

    'system_generated_effects' => [
        'journal_entry',
        'stock_movement',
        'cash_bank_transaction',
        'audit_log',
    ],
];
