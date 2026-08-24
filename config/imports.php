<?php

return [
    'max_rows' => 1000,
    'retention_days' => 30,
    'committing_timeout_minutes' => 30,
    'active_statuses' => ['validating', 'previewed', 'committing'],

    'profiles' => [
        'sales_invoice' => [
            'label' => 'Sales Invoice',
            'async' => true,
            'required_fields' => ['ref'],
            'fields' => ['ref', 'customer', 'invoice_date', 'due_date', 'item', 'quantity', 'unit_price', 'tax_code', 'notes'],
            'headers' => ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            'sample' => ['INV-20260811-001', 'PT Contoh Pelanggan', '11/08/2026', '25/08/2026', 'Jasa Konsultasi', '1', '1500000', 'PPN', 'Contoh baris templat'],
            'tax_codes' => ['PPN' => 11, 'PPN11' => 11, 'PPN12' => 12, 'VAT' => 11],
        ],
        'vendor_bill' => [
            'label' => 'Vendor Bill',
            'async' => true,
            'required_fields' => ['ref'],
            'fields' => ['ref', 'vendor', 'bill_date', 'due_date', 'item', 'quantity', 'unit_cost', 'tax_code', 'notes'],
            'headers' => ['Ref', 'Vendor', 'Bill Date', 'Due Date', 'Item', 'Quantity', 'Unit Cost', 'Tax Code', 'Notes'],
            'sample' => ['BILL-20260811-001', 'PT Contoh Vendor', '11/08/2026', '25/08/2026', 'Barang Contoh', '2', '750000', 'PPN', 'Contoh baris templat'],
            'tax_codes' => ['PPN' => 11, 'PPN11' => 11, 'PPN12' => 12, 'VAT' => 11],
        ],
        'journal_entry' => [
            'label' => 'Journal Entry',
            'async' => true,
            'required_fields' => ['ref'],
            'fields' => ['ref', 'journal_date', 'account_code', 'description', 'debit', 'credit', 'department', 'project'],
            'headers' => ['Ref', 'Journal Date', 'Account Code', 'Description', 'Debit', 'Credit', 'Department', 'Project'],
            // Dua baris contoh dengan Ref sama → satu jurnal seimbang (debit = kredit).
            'samples' => [
                ['JRN-20260811-001', '11/08/2026', '6100', 'Beban operasional', '100000', '0', 'OPS', ''],
                ['JRN-20260811-001', '11/08/2026', '1101', 'Kas kecil', '0', '100000', '', ''],
            ],
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
        // Saldo awal: satu berkas = seluruh isi SATU batch saldo awal (backend
        // hanya mengizinkan satu batch aktif per perusahaan), jadi profil ini
        // sengaja tidak punya kolom Ref seperti profil transaksi lain.
        'opening_balance' => [
            'label' => 'Saldo Awal',
            'required_fields' => ['account_code'],
            'fields' => ['account_code', 'description', 'debit', 'credit'],
            'headers' => ['Account Code', 'Description', 'Debit', 'Credit'],
            'samples' => [
                ['1100', 'Saldo kas per tanggal pembukuan', '25000000', '0'],
                ['1120', 'Piutang usaha awal', '15000000', '0'],
                ['2100', 'Hutang usaha awal', '0', '10000000'],
                ['3100', 'Modal disetor', '0', '30000000'],
            ],
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
