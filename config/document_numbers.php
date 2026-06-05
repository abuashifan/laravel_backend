<?php

return [
    'default_format' => '{PREFIX}-{YEAR}-{NUMBER}',

    'default_reset_period' => 'fiscal_year',

    'default_padding' => 6,

    'default_mode' => 'auto',

    'allow_manual_number_default' => false,

    'allow_duplicate_number_default' => false,

    'document_types' => [
        'journal_entry' => [
            'prefix' => 'JV',
            'name' => 'Journal Entry',
        ],
        'sales_quotation' => [
            'prefix' => 'SQ',
            'name' => 'Sales Quotation',
        ],
        'sales_order' => [
            'prefix' => 'SO',
            'name' => 'Sales Order',
        ],
        'delivery_order' => [
            'prefix' => 'DO',
            'name' => 'Delivery Order',
        ],
        'proforma_invoice' => [
            'prefix' => 'PF',
            'name' => 'Proforma Invoice',
        ],
        'sales_invoice' => [
            'prefix' => 'SI',
            'name' => 'Sales Invoice',
        ],
        'billing_invoice' => [
            'prefix' => 'BI',
            'name' => 'Billing Invoice',
        ],
        'sales_receipt' => [
            'prefix' => 'SR',
            'name' => 'Sales Receipt',
        ],
        'sales_return' => [
            'prefix' => 'SRT',
            'name' => 'Sales Return',
        ],
        'customer_deposit' => [
            'prefix' => 'CD',
            'name' => 'Customer Deposit',
        ],
        'purchase_request' => [
            'prefix' => 'PR',
            'name' => 'Purchase Request',
        ],
        'purchase_order' => [
            'prefix' => 'PO',
            'name' => 'Purchase Order',
        ],
        'goods_receipt' => [
            'prefix' => 'GR',
            'name' => 'Goods Receipt',
        ],
        'vendor_bill' => [
            'prefix' => 'VB',
            'name' => 'Vendor Bill',
        ],
        'purchase_invoice' => [
            'prefix' => 'PI',
            'name' => 'Purchase Invoice',
        ],
        'vendor_payment' => [
            'prefix' => 'VP',
            'name' => 'Vendor Payment',
        ],
        'vendor_deposit' => [
            'prefix' => 'VD',
            'name' => 'Vendor Deposit',
        ],
        'purchase_return' => [
            'prefix' => 'PRT',
            'name' => 'Purchase Return',
        ],
        'cash_receipt' => [
            'prefix' => 'CR',
            'name' => 'Cash Receipt',
        ],
        'cash_payment' => [
            'prefix' => 'CP',
            'name' => 'Cash Payment',
        ],
        'bank_transfer' => [
            'prefix' => 'BT',
            'name' => 'Bank Transfer',
        ],
        'bank_reconciliation' => [
            'prefix' => 'BR',
            'name' => 'Bank Reconciliation',
        ],
        'stock_adjustment' => [
            'prefix' => 'SA',
            'name' => 'Stock Adjustment',
        ],
        'stock_movement' => [
            'prefix' => 'SM',
            'name' => 'Stock Movement',
        ],
        'stock_opname' => [
            'prefix' => 'SO',
            'name' => 'Stock Opname',
        ],
        'stock_transfer' => [
            'prefix' => 'ST',
            'name' => 'Stock Transfer',
        ],
        'opening_stock' => [
            'prefix' => 'OS',
            'name' => 'Opening Stock',
        ],
        'opening_balance' => [
            'prefix' => 'OB',
            'name' => 'Opening Balance',
        ],
        'closing_entry' => [
            'prefix' => 'CL',
            'name' => 'Closing Entry',
        ],
    ],
];
