<?php

return [
    'max_rows' => 1000,
    'retention_days' => 30,
    'active_statuses' => ['validating', 'previewed', 'committing'],

    'profiles' => [
        'sales_invoice' => [
            'label' => 'Sales Invoice',
            'required_fields' => ['ref'],
            'headers' => ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            'sample' => ['INV-20260811-001', 'PT Contoh Pelanggan', '2026-08-11', '2026-08-25', 'Jasa Konsultasi', '1', '1500000', 'PPN', 'Contoh baris templat'],
        ],
        'vendor_bill' => [
            'label' => 'Vendor Bill',
            'required_fields' => ['ref'],
            'headers' => ['Ref', 'Vendor', 'Bill Date', 'Due Date', 'Item', 'Quantity', 'Unit Cost', 'Tax Code', 'Notes'],
            'sample' => ['BILL-20260811-001', 'PT Contoh Vendor', '2026-08-11', '2026-08-25', 'Barang Contoh', '2', '750000', 'PPN', 'Contoh baris templat'],
        ],
        'journal_entry' => [
            'label' => 'Journal Entry',
            'required_fields' => ['ref'],
            'headers' => ['Ref', 'Journal Date', 'Account Code', 'Description', 'Debit', 'Credit', 'Department', 'Project'],
            'sample' => ['JRN-20260811-001', '2026-08-11', '6100', 'Beban operasional', '100000', '0', 'OPS', ''],
        ],
        'contact' => [
            'label' => 'Contact',
            'required_fields' => ['name'],
            'headers' => ['Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
            'sample' => ['PT Contoh Relasi', 'customer', 'finance@example.test', '021-123456', 'Jakarta', ''],
        ],
    ],
];
