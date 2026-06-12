<?php

return [
    'document_types' => [
        'sales_quotation',
        'sales_order',
        'delivery_order',
        'proforma_invoice',
        'sales_invoice',
        'sales_receipt',
        'sales_return',
        'customer_deposit',
    ],

    'discount_types' => [
        'percent',
        'fixed_amount',
    ],

    'statuses' => [
        'draft',
        'approved',
        'confirmed',
        'issued',
        'shipped',
        'delivered',
        'posted',
        'cancelled',
        'void',
        'obsolete',
    ],

    'reportable_statuses' => [
        'approved',
        'confirmed',
        'issued',
        'shipped',
        'delivered',
        'posted',
    ],

    'hidden_statuses' => [
        'cancelled',
        'void',
        'obsolete',
    ],

    'account_mapping_aliases' => [
        'accounts_receivable' => 'sales.accounts_receivable',
        'sales_revenue' => 'sales.revenue',
        'sales_discount' => 'sales.discount',
        'sales_return' => 'sales.return',
        'customer_deposit' => 'sales.customer_deposit',
        'output_tax' => 'sales.tax_output',
        'cash_bank' => 'sales.default_cash_bank',
    ],
];
