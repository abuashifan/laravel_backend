<?php

/*
|--------------------------------------------------------------------------
| Penyambungan Akun untuk Kategori Aset Tetap Default
|--------------------------------------------------------------------------
|
| PENTING — file ini TIDAK mendefinisikan kategorinya. 15 kategori default
| (LAND … OTHER) sudah ditanam migration tenant
| `2026_06_15_000001_create_fixed_asset_tables.php` saat tabelnya dibuat, jadi
| setiap tenant selalu punya kategori sejak lahir. Nama, kelas aset, jenis
| penyusutan, dan umur manfaat default adalah milik migration itu — jangan
| diduplikasi di sini. Dua sumber kebenaran untuk daftar yang sama pasti
| melenceng (dan sempat melenceng saat file ini pertama disusun).
|
| Yang TIDAK dikerjakan migration itu adalah menyambungkan kategori ke akun
| COA: seluruh kolom `*_account_id` ditinggal null. Akibatnya
| `FixedAssetService::assetAccount()` dkk selalu jatuh ke mapping generik, dan
| 12 kunci mapping per kelas (kendaraan/gedung/peralatan/software) yang
| ditambahkan di commit 3e6bcbb tidak pernah menggerakkan jurnal — ia cuma
| jadi acuan saat user mengisi kategori manual.
|
| File ini menutup celah itu: peta kode kategori → kunci mapping akun, dipakai
| `FixedAssetCategoryAccountLinker` setelah template COA diterapkan.
|
| ── Nilainya daftar, bukan satu kunci ───────────────────────────────────────
| Kunci pertama yang berhasil di-resolve ke akun yang dipakai. Kunci generik di
| posisi terakhir adalah jaring pengaman untuk tenant lama yang COA-nya belum
| punya akun per kelas.
|
| Kategori yang TIDAK terdaftar di `account_links` (LAND, CIP, GOODWILL)
| sengaja dibiarkan null. Itu bukan kelalaian: templat COA belum punya akun
| khusus Tanah, Aset Dalam Penyelesaian, dan Goodwill, dan menyambungkannya ke
| akun Peralatan akan menaruh nilai tanah di baris peralatan pada neraca.
| Null = jatuh ke mapping generik, yaitu perilaku hari ini. Klien yang
| nilainya material sebaiknya membuat akunnya sendiri lalu menyetelnya di
| halaman Kategori Aset Tetap.
*/

$tangible = static fn (string $class): array => [
    'asset_account_id' => ["fixed_assets.{$class}_cost", 'fixed_assets.cost'],
    'accumulated_depreciation_account_id' => ["fixed_assets.{$class}_accumulated_depreciation", 'fixed_assets.accumulated_depreciation'],
    'depreciation_expense_account_id' => ["fixed_assets.{$class}_depreciation_expense", 'fixed_assets.depreciation_expense'],
];

$intangible = [
    'asset_account_id' => ['fixed_assets.software_cost', 'fixed_assets.cost'],
    'accumulated_depreciation_account_id' => ['fixed_assets.software_accumulated_amortization', 'fixed_assets.accumulated_amortization'],
    'depreciation_expense_account_id' => ['fixed_assets.software_amortization_expense', 'fixed_assets.amortization_expense'],
];

return [

    /*
    | Akun yang sama untuk semua kategori. Dipisah supaya tidak diulang 12 kali
    | dan tidak bisa lepas sinkron antar kategori.
    */
    'shared_accounts' => [
        'clearing_account_id' => ['fixed_assets.clearing'],
        'disposal_gain_account_id' => ['fixed_assets.disposal_gain'],
        'disposal_loss_account_id' => ['fixed_assets.disposal_loss'],
    ],

    'account_links' => [
        'BUILDING' => $tangible('building'),
        'VEHICLE' => $tangible('vehicle'),

        // Lima kategori berwujud sisanya berbagi akun Peralatan: templat COA
        // memisahkan aset tetap jadi empat kelas saja (kendaraan, gedung,
        // peralatan, perangkat lunak), bukan lima belas.
        'MACHINE' => $tangible('equipment'),
        'OFFICE_EQUIP' => $tangible('equipment'),
        'IT_EQUIP' => $tangible('equipment'),
        'FURNITURE' => $tangible('equipment'),
        'LEASEHOLD' => $tangible('equipment'),
        'OTHER' => $tangible('equipment'),

        // Tak berwujud beramortisasi berbagi akun Perangkat Lunak, dengan alasan
        // yang sama.
        'SOFTWARE' => $intangible,
        'PATENT' => $intangible,
        'COPYRIGHT' => $intangible,
        'TRADEMARK' => $intangible,
    ],
];
