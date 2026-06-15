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
        'fixed_asset' => [
            'prefix' => 'FA',
            'name' => 'Fixed Asset',
        ],
        'fixed_asset_capitalization' => [
            'prefix' => 'FAC',
            'name' => 'Fixed Asset Capitalization',
        ],
        'fixed_asset_depreciation' => [
            'prefix' => 'FAD',
            'name' => 'Fixed Asset Depreciation',
        ],
        'fixed_asset_disposal' => [
            'prefix' => 'FAS',
            'name' => 'Fixed Asset Disposal',
        ],
        'period_end' => [
            'prefix' => 'PE',
            'name' => 'Period End',
        ],
        'closing_entry' => [
            'prefix' => 'CL',
            'name' => 'Closing Entry',
        ],
    ],
];
