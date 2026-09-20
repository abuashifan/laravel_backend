<?php

return [
    'max_rows' => 1000,
    'retention_days' => 30,
    'committing_timeout_minutes' => 30,
    'active_statuses' => ['validating', 'previewed', 'committing'],

    /*
    |--------------------------------------------------------------------------
    | Alias header kolom
    |--------------------------------------------------------------------------
    |
    | Nama kolom lain yang dianggap sama dengan sebuah field, dipakai
    | `ImportBatchService::guessColumnMap()` supaya layar pemetaan tidak perlu
    | diisi manual.
    |
    | Templat unduhan memakai header berbahasa Inggris, sementara berkas yang
    | disusun sendiri oleh klien hampir selalu berbahasa Indonesia — "Kode Akun",
    | bukan "Account Code". Keduanya menunjuk field yang sama, jadi menolak yang
    | satu cuma memaksa user mengerjakan pekerjaan yang sudah bisa disimpulkan.
    |
    | Pencocokan mengabaikan huruf besar/kecil, spasi, dan tanda baca: "Kode Akun",
    | "kode_akun", dan "KODE AKUN" sama-sama cocok. Jadi daftar ini cukup memuat
    | satu bentuk per alias.
    */
    'column_aliases' => [
        'account_code' => ['kode akun', 'kode perkiraan', 'nomor akun', 'no akun', 'akun'],
        'description' => ['keterangan', 'deskripsi', 'uraian', 'catatan'],
        'debit' => ['debet'],
        'credit' => ['kredit'],
        'name' => ['nama', 'nama aset', 'nama barang'],
        'category' => ['kategori', 'kelompok'],
        'acquisition_date' => ['tanggal perolehan', 'tgl perolehan'],
        'acquisition_cost' => ['harga perolehan', 'nilai perolehan', 'harga beli'],
        'accumulated_depreciation' => ['akumulasi penyusutan', 'akum penyusutan', 'akumulasi depresiasi'],
        'salvage_value' => ['nilai residu', 'nilai sisa'],
        'useful_life_years' => ['umur manfaat', 'masa manfaat', 'umur ekonomis'],
        'quantity' => ['jumlah', 'kuantitas', 'qty'],
        'service_start_date' => ['tanggal mulai pakai', 'tanggal mulai digunakan', 'tgl mulai pakai'],
        'department' => ['departemen', 'divisi'],
        'project' => ['proyek'],
        'code' => ['kode'],
        'type' => ['tipe', 'jenis'],
        'parent_code' => ['kode induk', 'induk'],
        'cash_bank' => ['kas bank', 'kas/bank'],
        'ref' => ['referensi', 'no jurnal', 'nomor jurnal', 'no bukti'],
        'journal_date' => ['tanggal', 'tanggal jurnal', 'tgl jurnal'],
        'customer' => ['pelanggan', 'nama pelanggan'],
        'vendor' => ['pemasok', 'supplier', 'nama pemasok'],
        'invoice_date' => ['tanggal faktur', 'tgl faktur'],
        'bill_date' => ['tanggal tagihan', 'tgl tagihan'],
        'due_date' => ['jatuh tempo', 'tanggal jatuh tempo'],
        'item' => ['barang', 'produk', 'nama produk'],
        'unit_price' => ['harga satuan', 'harga jual'],
        'unit_cost' => ['harga beli satuan', 'harga pokok'],
        'tax_code' => ['kode pajak', 'pajak'],
        'notes' => ['catatan', 'keterangan tambahan'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Profil impor
    |--------------------------------------------------------------------------
    |
    | `money_fields` menandai field yang isinya nilai uang. Pratinjau impor
    | memakainya untuk memformat sel — tanpa penanda ini ia harus menebak dari
    | isinya, dan tebakan itu pasti salah: kode akun '1100' juga angka, tapi ia
    | bukan seribu seratus rupiah. Field angka yang BUKAN uang (kuantitas, umur
    | manfaat) sengaja tidak masuk daftar; angkanya kecil dan tidak perlu
    | pemisah ribuan.
    |
    */
    'profiles' => [
        'sales_invoice' => [
            'label' => 'Sales Invoice',
            'async' => true,
            'required_fields' => ['ref'],
            'fields' => ['ref', 'customer', 'invoice_date', 'due_date', 'item', 'quantity', 'unit_price', 'tax_code', 'notes'],
            'money_fields' => ['unit_price'],
            'headers' => ['Ref', 'Customer', 'Invoice Date', 'Due Date', 'Item', 'Quantity', 'Unit Price', 'Tax Code', 'Notes'],
            'sample' => ['INV-20260811-001', 'PT Contoh Pelanggan', '11/08/2026', '25/08/2026', 'Jasa Konsultasi', '1', '1500000', 'PPN', 'Contoh baris templat'],
            'tax_codes' => ['PPN' => 11, 'PPN11' => 11, 'PPN12' => 12, 'VAT' => 11],
        ],
        'vendor_bill' => [
            'label' => 'Vendor Bill',
            'async' => true,
            'required_fields' => ['ref'],
            'fields' => ['ref', 'vendor', 'bill_date', 'due_date', 'item', 'quantity', 'unit_cost', 'tax_code', 'notes'],
            'money_fields' => ['unit_cost'],
            'headers' => ['Ref', 'Vendor', 'Bill Date', 'Due Date', 'Item', 'Quantity', 'Unit Cost', 'Tax Code', 'Notes'],
            'sample' => ['BILL-20260811-001', 'PT Contoh Vendor', '11/08/2026', '25/08/2026', 'Barang Contoh', '2', '750000', 'PPN', 'Contoh baris templat'],
            'tax_codes' => ['PPN' => 11, 'PPN11' => 11, 'PPN12' => 12, 'VAT' => 11],
        ],
        'journal_entry' => [
            'label' => 'Journal Entry',
            'async' => true,
            'required_fields' => ['ref'],
            'fields' => ['ref', 'journal_date', 'account_code', 'description', 'debit', 'credit', 'department', 'project'],
            'money_fields' => ['debit', 'credit'],
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
        // Dua profil di bawah mengisi data SETUP AWAL, bukan transaksi harian.
        //
        // TIDAK ADA URUTAN WAJIB di antara keduanya -- Fase 8. Saldo awal adalah
        // impor jurnal biasa yang mengisi NILAI akun (termasuk akun aset tetap),
        // sementara impor aset tetap hanya mendaftarkan KARTU asetnya tanpa
        // jurnal sama sekali. Karena keduanya tidak saling menurunkan, berkas
        // neraca saldo klien boleh memuat akun harga perolehan dan akumulasi
        // penyusutan apa adanya, dan urutan impornya bebas.
        //
        // Selisih antara saldo akun dan kartu terdaftar bukan galat: ia dilaporkan
        // di papan Saldo Awal (`OpeningBalanceService::fixedAssetReconciliation`).
        'fixed_asset_opening' => [
            'label' => 'Aset Tetap Awal',
            'required_fields' => ['name', 'category', 'acquisition_date', 'acquisition_cost'],
            'fields' => ['name', 'category', 'acquisition_date', 'acquisition_cost', 'accumulated_depreciation', 'salvage_value', 'useful_life_years', 'quantity', 'service_start_date', 'department', 'project', 'description'],
            'money_fields' => ['acquisition_cost', 'accumulated_depreciation', 'salvage_value'],
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
            'money_fields' => ['debit', 'credit'],
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
