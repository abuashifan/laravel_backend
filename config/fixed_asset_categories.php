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
| Kunci pertama yang berhasil di-resolve ke akun yang dipakai. Kunci di posisi
| terakhir adalah jaring pengaman untuk tenant lama yang COA-nya belum punya
| akun per kelas: kelas Peralatan untuk penyusutan (kunci generik
| `fixed_assets.accumulated_depreciation` / `.depreciation_expense` sudah
| dihapus karena menduplikasi field per kelas di halaman Pemetaan Akun), dan
| kunci generik amortisasi yang memang belum dipecah.
|
| ── Kelas yang tidak disusutkan ────────────────────────────────────────────
| LAND, CIP, dan GOODWILL dulu sengaja TIDAK didaftarkan di sini, dengan alasan
| templat COA belum punya akunnya dan menyambungkannya ke akun Peralatan akan
| menaruh nilai tanah di baris peralatan pada neraca. Alasannya benar; obatnya
| yang keliru. Null tidak menahan apa pun -- ia jatuh ke fallback
| `fixed_assets.cost`, yang menunjuk akun Peralatan yang sama persis. Jadi
| kerugian yang hendak dihindari tetap terjadi, hanya lewat pintu lain, dan
| tanpa satu pun galat: bukan di pratinjau impor, bukan saat validasi saldo
| awal, bukan saat posting.
|
| Sekarang ketiganya punya akun sendiri di templat COA (1500 / 1550 / 1560) dan
| kunci mapping sendiri, jadi ia disambungkan seperti kelas lain. Yang berbeda
| cuma satu: HANYA `asset_account_id`. Tidak ada akun akumulasi dan tidak ada
| akun beban, karena tidak ada yang disusutkan -- mendaftarkannya justru akan
| menghidupkan kembali jalur salah kelas yang baru saja ditutup.
|
| Penjagaannya sendiri tidak di file ini, melainkan di
| `FixedAssetService::assetAccount()`: kategori non-penyusutan yang akunnya
| belum tersetel ditolak, bukan dilempar diam-diam ke akun Peralatan.
*/

// array_unique: untuk kelas `equipment`, kunci utama dan kunci cadangannya sama
// persis, jadi tanpa ini daftarnya memuat kunci kembar yang tidak berguna.
$tangible = static fn (string $class): array => [
    'asset_account_id' => array_values(array_unique(["fixed_assets.{$class}_cost", 'fixed_assets.cost'])),
    'accumulated_depreciation_account_id' => array_values(array_unique(["fixed_assets.{$class}_accumulated_depreciation", 'fixed_assets.equipment_accumulated_depreciation'])),
    'depreciation_expense_account_id' => array_values(array_unique(["fixed_assets.{$class}_depreciation_expense", 'fixed_assets.equipment_depreciation_expense'])),
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

        // Kelas yang tidak disusutkan: hanya akun harga perolehan, dan TANPA
        // rantai fallback. Daftar berisi satu kunci saja adalah bagian dari
        // aturannya -- menambahkan `fixed_assets.cost` sebagai cadangan akan
        // mengembalikan tepat bug yang ditutup: tanah mendarat di Peralatan.
        // Tenant lama yang COA-nya belum punya 1500/1550/1560 akan tetap null di
        // sini, dan `assetAccount()` menolaknya dengan pesan yang menyebut akun
        // mana yang harus dibuat.
        'LAND' => ['asset_account_id' => ['fixed_assets.land_cost']],
        'CIP' => ['asset_account_id' => ['fixed_assets.construction_in_progress_cost']],
        'GOODWILL' => ['asset_account_id' => ['fixed_assets.goodwill_cost']],
    ],
];
