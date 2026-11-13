<?php

/*
 * Finlite Kasir (POS).
 *
 * `role_templates`: templat izin saat pemilik mengundang atau mengubah peran
 * pengguna toko dari aplikasi kasir. Kunci wajib ada di permission_matrix
 * (modul `pos`). Pemilik selalu Akses penuh; templat tidak memberi izin
 * `access.*`, jadi hanya pemilik yang mengatur pengguna toko.
 */

$kasir = [
    'pos.sales.view',
    'pos.sales.create',
    'pos.sales.print',
    'pos.products.view',
    'pos.customers.view',
    'pos.customers.create',
    'pos.shifts.view',
    'pos.shifts.create',
];

return [
    'role_templates' => [
        'kasir' => [
            'label' => 'Kasir',
            'description' => 'Berjualan, buka/tutup shift sendiri, tambah pelanggan.',
            'permissions' => $kasir,
        ],
        'kepala_toko' => [
            'label' => 'Kepala toko',
            'description' => 'Semua tugas kasir, plus produk & stok, batalkan transaksi, diskon tanpa batas, kasbon, laporan, log aktivitas, dan shift semua kasir.',
            'permissions' => array_merge($kasir, [
                'pos.sales.delete',
                'pos.sales.export',
                'pos.products.create',
                'pos.products.edit',
                'pos.products.delete',
                'pos.products.export',
                'pos.stock.adjust',
                'pos.customers.edit',
                'pos.customers.delete',
                'pos.shifts.view_all',
                'pos.reports.view',
                'pos.reports.export',
                'pos.settings.view',
                'pos.audit.view',
                'pos.discount.override',
            ]),
        ],
    ],

    // Nomor WhatsApp penjual untuk tombol "Berlangganan" di aplikasi kasir
    // (format 628xxx). Kosong = tombol tidak tampil. Pembayaran masih manual:
    // admin memperpanjang dari panel admin setelah pembayaran diterima.
    'sales_whatsapp' => env('POS_SALES_WHATSAPP', ''),

    // Kuota total foto produk per toko (MB). Foto disimpan di database toko
    // (~30-60 KB per produk setelah diperkecil aplikasi kasir).
    'photo_quota_mb' => (int) env('POS_PHOTO_QUOTA_MB', 50),
];
