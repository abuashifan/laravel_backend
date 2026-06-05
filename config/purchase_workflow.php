<?php

return [
    'document_types' => [
        'purchase_request',
        'purchase_order',
        'goods_receipt',
        'vendor_bill',
        'vendor_payment',
        'vendor_deposit',
        'purchase_return',
    ],

    'discount_types' => [
        'percent',
        'fixed_amount',
    ],

    'statuses' => [
        'draft',
        'submitted',
        'approved',
        'rejected',
        'confirmed',
        'received',
        'posted',
        'paid',
        'cancelled',
        'void',
        'obsolete',
    ],

    'reportable_statuses' => [
        'approved',
        'confirmed',
        'received',
        'posted',
        'paid',
    ],

    'hidden_statuses' => [
        'cancelled',
        'void',
        'obsolete',
    ],

    'account_mapping_aliases' => [
        'accounts_payable' => 'purchase.accounts_payable',
        'purchase_expense' => 'purchase.expense',
        'inventory_interim' => 'purchase.inventory_interim',
        'input_tax' => 'purchase.tax_input',
        'purchase_discount' => 'purchase.discount',
        'purchase_return' => 'purchase.return',
        'vendor_deposit' => 'purchase.vendor_deposit',
        'cash_bank' => 'purchase.default_cash_bank',
    ],
];
