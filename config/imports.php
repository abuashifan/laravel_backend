<?php

return [
    'max_rows' => 1000,
    'retention_days' => 30,
    'active_statuses' => ['validating', 'previewed', 'committing'],

    'profiles' => [
        'sales_invoice' => [
            'label' => 'Sales Invoice',
            'async' => true,
            'required_fields' => ['ref'],
            'headers' => ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            'sample' => ['INV-20260811-001', 'PT Contoh Pelanggan', '2026-08-11', '2026-08-25', 'Jasa Konsultasi', '1', '1500000', 'PPN', 'Contoh baris templat'],
        ],
        'vendor_bill' => [
            'label' => 'Vendor Bill',
            'async' => true,
            'required_fields' => ['ref'],
            'headers' => ['Ref', 'Vendor', 'Bill Date', 'Due Date', 'Item', 'Quantity', 'Unit Cost', 'Tax Code', 'Notes'],
            'sample' => ['BILL-20260811-001', 'PT Contoh Vendor', '2026-08-11', '2026-08-25', 'Barang Contoh', '2', '750000', 'PPN', 'Contoh baris templat'],
        ],
        'journal_entry' => [
            'label' => 'Journal Entry',
            'async' => true,
            'required_fields' => ['ref'],
            'headers' => ['Ref', 'Journal Date', 'Account Code', 'Description', 'Debit', 'Credit', 'Department', 'Project'],
            'sample' => ['JRN-20260811-001', '2026-08-11', '6100', 'Beban operasional', '100000', '0', 'OPS', ''],
        ],
        // Tiga profil di bawah (Fase 1, rencana impor data) punya 'fields' --
        // daftar kunci field snake_case, sejajar 1:1 dengan 'headers'. Dipakai
        // frontend membangun column_map otomatis saat client memakai templat
        // unduhan apa adanya (jalur yang dipakai ~90% waktu), tanpa menebak
        // dari teks header yang bisa berubah terjemahannya.
        'contact' => [
            'label' => 'Contact',
            'required_fields' => ['name'],
            'fields' => ['code', 'name', 'type', 'email', 'phone', 'address', 'tax_number'],
            'headers' => ['Code', 'Name', 'Type', 'Email', 'Phone', 'Address', 'Tax Number'],
            'sample' => ['', 'PT Contoh Relasi', 'customer', 'finance@example.test', '021-123456', 'Jakarta', ''],
        ],
        'product' => [
            'label' => 'Product',
            'required_fields' => ['name'],
            'fields' => ['code', 'name', 'type', 'category', 'unit', 'stock_item', 'min_stock'],
            'headers' => ['Code', 'Name', 'Type', 'Category', 'Unit', 'Stock Item', 'Min Stock'],
            'sample' => ['', 'Kertas A4', 'goods', 'Alat Tulis Kantor', 'PCS', 'yes', '0'],
        ],
        'chart_of_account' => [
            'label' => 'Chart of Account',
            'required_fields' => ['code', 'name', 'type'],
            'fields' => ['code', 'name', 'type', 'parent_code', 'cash_bank'],
            'headers' => ['Code', 'Name', 'Type', 'Parent Code', 'Cash/Bank'],
            'sample' => ['1101', 'Kas Kecil', 'asset', '1100', 'yes'],
        ],
    ],
];
