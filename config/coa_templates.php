<?php

/**
 * Template Chart of Accounts untuk Setup Wizard.
 *
 * Tiap `accounts` WAJIB terurut induk lebih dulu daripada anak
 * (`CoaTemplateService::applyTemplate()` resolve `parent_code` -> id dengan
 * satu kali jalan, bukan multi-pass).
 *
 * Kode akun sengaja disamakan dengan `default_account_codes` di
 * `config/account_mappings.php` untuk key yang sama supaya
 * `AccountMappingStorageService::syncDefaultMappingsFromConfig()` otomatis
 * memetakan akun hasil template tanpa logika tambahan. Jangan ubah kode akun
 * inti (1100, 1110, 1120, 1130, 1140, 2100, 2120, 2130, 2140, 2150, 3100,
 * 3200, 3300, 4100, 4110, 4120, 5100, 5110, 5120, 6100, 6160, 7100, 7200,
 * 1590, 8200) tanpa juga menyesuaikan `account_mappings.php`.
 *
 * Resolusi default itu MELEWATI akun induk -- akun induk ditolak saat posting
 * jurnal, jadi memetakannya cuma menunda kegagalan sampai transaksi pertama.
 * Karena itu kode inti di atas boleh saja jadi induk di sebagian template
 * (mis. 1130 Persediaan beranak 1131/1132 di gas_agent/trading/manufacture):
 * `default_account_codes` tinggal mencantumkan kode anaknya sebagai lanjutan.
 * Yang tidak boleh: kode inti jadi induk TANPA ada kode leaf pengganti di
 * daftar `default_account_codes` milik key yang bersangkutan.
 *
 * Aset tetap dipecah per kelas, bukan satu akun gabungan, supaya neraca dan
 * jurnal penyusutan bisa dibaca per jenis aset tanpa membongkar COA lagi:
 *
 *   Kendaraan       1510 / akum. 1511 / beban 6170
 *   Gedung          1520 / akum. 1521 / beban 6171
 *   Peralatan       1530 / akum. 1531 / beban 6172
 *   Perangkat Lunak 1540 / akum. 1541 / beban 6175  (amortisasi, aset tak berwujud)
 *
 * Tiga kelas berikut TIDAK disusutkan, jadi ia tidak punya akun akumulasi
 * maupun akun beban -- hanya akun harga perolehan:
 *
 *   Tanah                   1500
 *   Aset Dalam Penyelesaian 1550
 *   Goodwill                1560
 *
 * Ketiganya wajib ada di template. Sebelumnya tidak, dan akibatnya kategori
 * LAND/CIP/GOODWILL jatuh ke fallback `fixed_assets.cost` yang menunjuk akun
 * Peralatan -- nilai tanah muncul di baris Peralatan pada neraca tanpa satu
 * pun galat. Sejak `FixedAssetService::assetAccount()` menolak fallback lintas
 * kelas, akun-akun ini bukan lagi kenyamanan tapi prasyarat.
 *
 * Kelas Peralatan sekaligus jadi fallback untuk key generik `fixed_assets.cost`,
 * `.accumulated_depreciation`, dan `.depreciation_expense` -- akun induk `15`
 * tidak bisa dipakai transaksi, jadi fallback harus menunjuk akun leaf.
 * 1520/1521 tetap dipakai (kini untuk Gedung) supaya tenant yang sudah
 * menerapkan template versi lama tetap punya akun dengan kode yang sama.
 *
 * `normal_balance` sengaja tidak diisi di sini -- diturunkan dari `type` oleh
 * `ChartOfAccountService::validateNormalBalance()` saat akun dibuat.
 */

return [
    'templates' => [
        'gas_agent' => [
            'label' => 'Agen Gas',
            'description' => 'COA standar untuk bisnis distribusi gas LPG',
            'accounts' => [
                ['code' => '1', 'name' => 'AKTIVA LANCAR', 'type' => 'asset', 'parent_code' => null],
                ['code' => '1100', 'name' => 'Kas', 'type' => 'asset', 'parent_code' => '1', 'is_cash_bank' => true],
                ['code' => '1110', 'name' => 'Bank', 'type' => 'asset', 'parent_code' => '1', 'is_cash_bank' => true],
                ['code' => '1120', 'name' => 'Piutang Usaha', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '1130', 'name' => 'Persediaan', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '1131', 'name' => 'Persediaan Gas LPG', 'type' => 'asset', 'parent_code' => '1130'],
                ['code' => '1132', 'name' => 'Persediaan Tabung Kosong', 'type' => 'asset', 'parent_code' => '1130'],
                ['code' => '1140', 'name' => 'Uang Muka Pembelian', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '2140', 'name' => 'PPN Masukan', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '15', 'name' => 'ASET TETAP', 'type' => 'asset', 'parent_code' => null],
                ['code' => '1500', 'name' => 'Tanah', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1510', 'name' => 'Kendaraan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1511', 'name' => 'Akumulasi Penyusutan Kendaraan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1520', 'name' => 'Gedung', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1521', 'name' => 'Akumulasi Penyusutan Gedung', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1530', 'name' => 'Peralatan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1531', 'name' => 'Akumulasi Penyusutan Peralatan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1540', 'name' => 'Perangkat Lunak (Software)', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1541', 'name' => 'Akumulasi Amortisasi Perangkat Lunak', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1550', 'name' => 'Aset Dalam Penyelesaian', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1560', 'name' => 'Goodwill', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1590', 'name' => 'Fixed Asset Clearing', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '2', 'name' => 'KEWAJIBAN LANCAR', 'type' => 'liability', 'parent_code' => null],
                ['code' => '2100', 'name' => 'Hutang Usaha', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2120', 'name' => 'PPN Keluaran', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2130', 'name' => 'Uang Muka Penjualan', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2150', 'name' => 'Penerimaan Barang (GRNI)', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2160', 'name' => 'Deposit Tabung Pelanggan', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '3', 'name' => 'MODAL', 'type' => 'equity', 'parent_code' => null],
                ['code' => '3100', 'name' => 'Modal Pemilik', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '3200', 'name' => 'Laba Ditahan', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '3300', 'name' => 'Laba Tahun Berjalan', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '4', 'name' => 'PENDAPATAN', 'type' => 'revenue', 'parent_code' => null],
                ['code' => '4100', 'name' => 'Pendapatan Penjualan Gas', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '4110', 'name' => 'Retur Penjualan', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '4120', 'name' => 'Diskon Penjualan', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '4130', 'name' => 'Pendapatan Jasa Antar', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '7100', 'name' => 'Pendapatan Bunga Bank', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '7200', 'name' => 'Laba Pelepasan Aset Tetap', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '5', 'name' => 'BEBAN POKOK PENJUALAN', 'type' => 'expense', 'parent_code' => null],
                ['code' => '5100', 'name' => 'Harga Pokok Penjualan', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5110', 'name' => 'Diskon Pembelian', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5111', 'name' => 'Retur Pembelian', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5120', 'name' => 'Selisih Persediaan', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '6', 'name' => 'BEBAN OPERASIONAL', 'type' => 'expense', 'parent_code' => null],
                ['code' => '6100', 'name' => 'Beban Operasional Umum', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6110', 'name' => 'Beban Bahan Bakar Kendaraan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6120', 'name' => 'Beban Pemeliharaan Kendaraan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6160', 'name' => 'Biaya Admin Bank', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6170', 'name' => 'Beban Penyusutan Kendaraan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6171', 'name' => 'Beban Penyusutan Gedung', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6172', 'name' => 'Beban Penyusutan Peralatan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6175', 'name' => 'Beban Amortisasi Perangkat Lunak', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '8', 'name' => 'BEBAN LAIN-LAIN', 'type' => 'expense', 'parent_code' => null],
                ['code' => '8200', 'name' => 'Rugi Pelepasan Aset Tetap', 'type' => 'expense', 'parent_code' => '8'],
            ],
        ],

        'trading' => [
            'label' => 'Perdagangan Umum',
            'description' => 'COA standar untuk bisnis dagang barang',
            'accounts' => [
                ['code' => '1', 'name' => 'AKTIVA LANCAR', 'type' => 'asset', 'parent_code' => null],
                ['code' => '1100', 'name' => 'Kas', 'type' => 'asset', 'parent_code' => '1', 'is_cash_bank' => true],
                ['code' => '1110', 'name' => 'Bank', 'type' => 'asset', 'parent_code' => '1', 'is_cash_bank' => true],
                ['code' => '1120', 'name' => 'Piutang Usaha', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '1130', 'name' => 'Persediaan', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '1131', 'name' => 'Persediaan Barang Dagang A', 'type' => 'asset', 'parent_code' => '1130'],
                ['code' => '1132', 'name' => 'Persediaan Barang Dagang B', 'type' => 'asset', 'parent_code' => '1130'],
                ['code' => '1140', 'name' => 'Uang Muka Pembelian', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '2140', 'name' => 'PPN Masukan', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '15', 'name' => 'ASET TETAP', 'type' => 'asset', 'parent_code' => null],
                ['code' => '1500', 'name' => 'Tanah', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1510', 'name' => 'Kendaraan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1511', 'name' => 'Akumulasi Penyusutan Kendaraan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1520', 'name' => 'Gedung', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1521', 'name' => 'Akumulasi Penyusutan Gedung', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1530', 'name' => 'Peralatan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1531', 'name' => 'Akumulasi Penyusutan Peralatan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1540', 'name' => 'Perangkat Lunak (Software)', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1541', 'name' => 'Akumulasi Amortisasi Perangkat Lunak', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1550', 'name' => 'Aset Dalam Penyelesaian', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1560', 'name' => 'Goodwill', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1590', 'name' => 'Fixed Asset Clearing', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '2', 'name' => 'KEWAJIBAN LANCAR', 'type' => 'liability', 'parent_code' => null],
                ['code' => '2100', 'name' => 'Hutang Usaha', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2120', 'name' => 'PPN Keluaran', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2130', 'name' => 'Uang Muka Penjualan', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2150', 'name' => 'Penerimaan Barang (GRNI)', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '3', 'name' => 'MODAL', 'type' => 'equity', 'parent_code' => null],
                ['code' => '3100', 'name' => 'Modal Pemilik', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '3200', 'name' => 'Laba Ditahan', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '3300', 'name' => 'Laba Tahun Berjalan', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '4', 'name' => 'PENDAPATAN', 'type' => 'revenue', 'parent_code' => null],
                ['code' => '4100', 'name' => 'Pendapatan Penjualan', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '4110', 'name' => 'Retur Penjualan', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '4120', 'name' => 'Diskon Penjualan', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '7100', 'name' => 'Pendapatan Bunga Bank', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '7200', 'name' => 'Laba Pelepasan Aset Tetap', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '5', 'name' => 'BEBAN POKOK PENJUALAN', 'type' => 'expense', 'parent_code' => null],
                ['code' => '5100', 'name' => 'Harga Pokok Penjualan', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5110', 'name' => 'Diskon Pembelian', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5111', 'name' => 'Retur Pembelian', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5120', 'name' => 'Selisih Persediaan', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '6', 'name' => 'BEBAN OPERASIONAL', 'type' => 'expense', 'parent_code' => null],
                ['code' => '6100', 'name' => 'Beban Operasional Umum', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6110', 'name' => 'Beban Sewa Gudang', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6111', 'name' => 'Beban Angkut Pembelian', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6112', 'name' => 'Beban Angkut Penjualan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6160', 'name' => 'Biaya Admin Bank', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6170', 'name' => 'Beban Penyusutan Kendaraan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6171', 'name' => 'Beban Penyusutan Gedung', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6172', 'name' => 'Beban Penyusutan Peralatan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6175', 'name' => 'Beban Amortisasi Perangkat Lunak', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '8', 'name' => 'BEBAN LAIN-LAIN', 'type' => 'expense', 'parent_code' => null],
                ['code' => '8200', 'name' => 'Rugi Pelepasan Aset Tetap', 'type' => 'expense', 'parent_code' => '8'],
            ],
        ],

        'service' => [
            'label' => 'Jasa',
            'description' => 'COA standar untuk bisnis jasa dan konsultan',
            'accounts' => [
                ['code' => '1', 'name' => 'AKTIVA LANCAR', 'type' => 'asset', 'parent_code' => null],
                ['code' => '1100', 'name' => 'Kas', 'type' => 'asset', 'parent_code' => '1', 'is_cash_bank' => true],
                ['code' => '1110', 'name' => 'Bank', 'type' => 'asset', 'parent_code' => '1', 'is_cash_bank' => true],
                ['code' => '1120', 'name' => 'Piutang Usaha', 'type' => 'asset', 'parent_code' => '1'],
                // Sengaja leaf (tanpa akun anak): perusahaan jasa yang juga
                // mengaktifkan modul Persediaan butuh akun ini supaya mapping
                // inventory.asset punya akun yang bisa diposting.
                ['code' => '1130', 'name' => 'Persediaan Perlengkapan', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '1140', 'name' => 'Uang Muka Pembelian', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '2140', 'name' => 'PPN Masukan', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '15', 'name' => 'ASET TETAP', 'type' => 'asset', 'parent_code' => null],
                ['code' => '1500', 'name' => 'Tanah', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1510', 'name' => 'Kendaraan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1511', 'name' => 'Akumulasi Penyusutan Kendaraan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1520', 'name' => 'Gedung', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1521', 'name' => 'Akumulasi Penyusutan Gedung', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1530', 'name' => 'Peralatan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1531', 'name' => 'Akumulasi Penyusutan Peralatan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1540', 'name' => 'Perangkat Lunak (Software)', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1541', 'name' => 'Akumulasi Amortisasi Perangkat Lunak', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1550', 'name' => 'Aset Dalam Penyelesaian', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1560', 'name' => 'Goodwill', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1590', 'name' => 'Fixed Asset Clearing', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '2', 'name' => 'KEWAJIBAN LANCAR', 'type' => 'liability', 'parent_code' => null],
                ['code' => '2100', 'name' => 'Hutang Usaha', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2120', 'name' => 'PPN Keluaran', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2130', 'name' => 'Uang Muka Penjualan', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '3', 'name' => 'MODAL', 'type' => 'equity', 'parent_code' => null],
                ['code' => '3100', 'name' => 'Modal Pemilik', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '3200', 'name' => 'Laba Ditahan', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '3300', 'name' => 'Laba Tahun Berjalan', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '4', 'name' => 'PENDAPATAN', 'type' => 'revenue', 'parent_code' => null],
                ['code' => '4100', 'name' => 'Pendapatan Jasa', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '4120', 'name' => 'Diskon Jasa', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '7100', 'name' => 'Pendapatan Bunga Bank', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '7200', 'name' => 'Laba Pelepasan Aset Tetap', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '5', 'name' => 'BEBAN POKOK JASA', 'type' => 'expense', 'parent_code' => null],
                ['code' => '5100', 'name' => 'Harga Pokok Jasa', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5120', 'name' => 'Selisih Persediaan', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '6', 'name' => 'BEBAN OPERASIONAL', 'type' => 'expense', 'parent_code' => null],
                ['code' => '6100', 'name' => 'Beban Operasional Umum', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6130', 'name' => 'Beban Gaji Karyawan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6140', 'name' => 'Beban Sewa Kantor', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6160', 'name' => 'Biaya Admin Bank', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6170', 'name' => 'Beban Penyusutan Kendaraan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6171', 'name' => 'Beban Penyusutan Gedung', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6172', 'name' => 'Beban Penyusutan Peralatan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6175', 'name' => 'Beban Amortisasi Perangkat Lunak', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '8', 'name' => 'BEBAN LAIN-LAIN', 'type' => 'expense', 'parent_code' => null],
                ['code' => '8200', 'name' => 'Rugi Pelepasan Aset Tetap', 'type' => 'expense', 'parent_code' => '8'],
            ],
        ],

        'manufacture' => [
            'label' => 'Manufaktur',
            'description' => 'COA standar untuk bisnis produksi',
            'accounts' => [
                ['code' => '1', 'name' => 'AKTIVA LANCAR', 'type' => 'asset', 'parent_code' => null],
                ['code' => '1100', 'name' => 'Kas', 'type' => 'asset', 'parent_code' => '1', 'is_cash_bank' => true],
                ['code' => '1110', 'name' => 'Bank', 'type' => 'asset', 'parent_code' => '1', 'is_cash_bank' => true],
                ['code' => '1120', 'name' => 'Piutang Usaha', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '1130', 'name' => 'Persediaan', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '1131', 'name' => 'Persediaan Bahan Baku', 'type' => 'asset', 'parent_code' => '1130'],
                ['code' => '1132', 'name' => 'Persediaan Barang Dalam Proses', 'type' => 'asset', 'parent_code' => '1130'],
                ['code' => '1133', 'name' => 'Persediaan Barang Jadi', 'type' => 'asset', 'parent_code' => '1130'],
                ['code' => '1140', 'name' => 'Uang Muka Pembelian', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '2140', 'name' => 'PPN Masukan', 'type' => 'asset', 'parent_code' => '1'],
                ['code' => '15', 'name' => 'ASET TETAP', 'type' => 'asset', 'parent_code' => null],
                ['code' => '1500', 'name' => 'Tanah', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1510', 'name' => 'Kendaraan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1511', 'name' => 'Akumulasi Penyusutan Kendaraan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1520', 'name' => 'Gedung', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1521', 'name' => 'Akumulasi Penyusutan Gedung', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1530', 'name' => 'Peralatan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1531', 'name' => 'Akumulasi Penyusutan Peralatan', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1540', 'name' => 'Perangkat Lunak (Software)', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1541', 'name' => 'Akumulasi Amortisasi Perangkat Lunak', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1550', 'name' => 'Aset Dalam Penyelesaian', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1560', 'name' => 'Goodwill', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '1590', 'name' => 'Fixed Asset Clearing', 'type' => 'asset', 'parent_code' => '15'],
                ['code' => '2', 'name' => 'KEWAJIBAN LANCAR', 'type' => 'liability', 'parent_code' => null],
                ['code' => '2100', 'name' => 'Hutang Usaha', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2120', 'name' => 'PPN Keluaran', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2130', 'name' => 'Uang Muka Penjualan', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '2150', 'name' => 'Penerimaan Barang (GRNI)', 'type' => 'liability', 'parent_code' => '2'],
                ['code' => '3', 'name' => 'MODAL', 'type' => 'equity', 'parent_code' => null],
                ['code' => '3100', 'name' => 'Modal Pemilik', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '3200', 'name' => 'Laba Ditahan', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '3300', 'name' => 'Laba Tahun Berjalan', 'type' => 'equity', 'parent_code' => '3'],
                ['code' => '4', 'name' => 'PENDAPATAN', 'type' => 'revenue', 'parent_code' => null],
                ['code' => '4100', 'name' => 'Pendapatan Penjualan', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '4110', 'name' => 'Retur Penjualan', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '4120', 'name' => 'Diskon Penjualan', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '7100', 'name' => 'Pendapatan Bunga Bank', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '7200', 'name' => 'Laba Pelepasan Aset Tetap', 'type' => 'revenue', 'parent_code' => '4'],
                ['code' => '5', 'name' => 'BEBAN POKOK PRODUKSI', 'type' => 'expense', 'parent_code' => null],
                ['code' => '5100', 'name' => 'Harga Pokok Penjualan', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5110', 'name' => 'Diskon Pembelian', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5111', 'name' => 'Retur Pembelian', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '5120', 'name' => 'Selisih Persediaan', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '6150', 'name' => 'Beban Tenaga Kerja Langsung', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '6151', 'name' => 'Beban Overhead Pabrik', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '6152', 'name' => 'Beban Penyusutan Mesin Pabrik', 'type' => 'expense', 'parent_code' => '5'],
                ['code' => '6', 'name' => 'BEBAN OPERASIONAL', 'type' => 'expense', 'parent_code' => null],
                ['code' => '6100', 'name' => 'Beban Operasional Umum', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6160', 'name' => 'Biaya Admin Bank', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6170', 'name' => 'Beban Penyusutan Kendaraan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6171', 'name' => 'Beban Penyusutan Gedung', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6172', 'name' => 'Beban Penyusutan Peralatan', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '6175', 'name' => 'Beban Amortisasi Perangkat Lunak', 'type' => 'expense', 'parent_code' => '6'],
                ['code' => '8', 'name' => 'BEBAN LAIN-LAIN', 'type' => 'expense', 'parent_code' => null],
                ['code' => '8200', 'name' => 'Rugi Pelepasan Aset Tetap', 'type' => 'expense', 'parent_code' => '8'],
            ],
        ],

        'blank' => [
            'label' => 'Kosong',
            'description' => 'Mulai dari nol, buat COA sendiri',
            'accounts' => [],
        ],
    ],
];
