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
        // Dua profil di bawah (Fase 7) mengisi data SETUP AWAL, bukan transaksi
        // harian. Keduanya sinkron: committer-nya tidak memposting jurnal apa
        // pun -- saldo awal hanya menulis baris draft batch, aset tetap hanya
        // membuat register aset. Jurnal baru lahir saat user menekan Posting di
        // halaman Saldo Awal.
        //
        // URUTAN WAJIB: 'fixed_asset_opening' DULU, baru 'opening_balance'.
        // OpeningBalanceBatchService::fixedAssetSystemLines() mengubah aset
        // ber-source_type 'opening_import' jadi baris kontrol otomatis di batch
        // saldo awal, dan menolak baris manual dengan akun yang sama
        // (FIXED_ASSET_CONTROL_DUPLICATE). Karena itu berkas saldo awal TIDAK
        // BOLEH memuat akun harga perolehan / akumulasi penyusutan aset tetap.
        'fixed_asset_opening' => [
            'label' => 'Aset Tetap Awal',
            'required_fields' => ['name', 'category', 'acquisition_date', 'acquisition_cost'],
            'fields' => ['name', 'category', 'acquisition_date', 'acquisition_cost', 'accumulated_depreciation', 'salvage_value', 'useful_life_years', 'quantity', 'service_start_date', 'department', 'project', 'description'],
            'headers' => ['Name', 'Category', 'Acquisition Date', 'Acquisition Cost', 'Accumulated Depreciation', 'Salvage Value', 'Useful Life Years', 'Quantity', 'Service Start Date', 'Department', 'Project', 'Description'],
            'samples' => [
                ['Toyota Avanza B 1234 XYZ', 'VEHICLE', '15/03/2023', '250000000', '75000000', '0', '8', '1', '15/03/2023', '', '', 'Kendaraan operasional'],
                ['Laptop Dell Latitude', 'IT_EQUIP', '01/07/2024', '18000000', '4500000', '0', '4', '1', '01/07/2024', '', '', ''],
            ],
        ],
        'opening_balance' => [
            'label' => 'Saldo Awal',
            'required_fields' => ['account_code'],
            'fields' => ['account_code', 'description', 'debit', 'credit'],
            'headers' => ['Account Code', 'Description', 'Debit', 'Credit'],
            // Dua baris contoh: satu sisi debit, satu sisi kredit -- mengisyaratkan
            // bahwa berkasnya adalah neraca saldo, bukan daftar satu sisi.
            'samples' => [
                ['1101', 'Saldo awal kas kecil', '5000000', '0'],
                ['3100', 'Saldo awal modal disetor', '0', '5000000'],
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
