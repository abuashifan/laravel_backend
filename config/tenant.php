<?php

return [
    /*
     * Driver untuk tenant yang AKAN DIBUAT. Tenant yang sudah ada tetap mengikuti
     * kolom `driver` di barisnya masing-masing, jadi mengubah nilai ini tidak
     * membuat tenant lama tidak terbaca.
     *
     * - `sqlite` (default) — satu berkas per perusahaan. Dipakai test dan lokal.
     * - `pgsql` — satu schema per perusahaan. Wajib di production: berkas di
     *   container Render ikut terhapus setiap deploy.
     */
    'driver' => env('TENANT_DRIVER', 'sqlite'),

    /*
     * Koneksi Postgres yang schema tenant-nya dititipkan. Default mengikuti
     * koneksi central, karena tenant memang tinggal di database yang sama —
     * yang membedakan hanya search_path.
     */
    'pgsql_connection' => env('TENANT_PGSQL_CONNECTION'),

    /*
     * Folder berkas tenant sqlite. Bisa dipindah lewat env supaya uji E2E
     * (repo finlite_pos, folder e2e) memakai folder sementara dan tidak
     * bertabrakan dengan berkas tenant dev di database/tenants.
     */
    'database_path' => env('TENANT_DATABASE_PATH', database_path('tenants')),

    'connection_name' => 'tenant',

    'database_prefix' => 'company_',

    'database_extension' => '.sqlite',

    'schema_prefix' => 'tenant_',

    /*
     * Produk ekosistem yang punya tenant sendiri. `suffix` ditempel ke nama
     * berkas/schema tenant (accounting tanpa suffix supaya tenant lama tetap
     * bernama sama). `migration_path` relatif ke base_path.
     */
    'products' => [
        'accounting' => [
            'label' => 'Akuntansi',
            'suffix' => '',
            'migration_path' => 'database/migrations/tenant',
            'connection' => 'tenant',
        ],
        'pos' => [
            'label' => 'Kasir (POS)',
            'suffix' => '_pos',
            'migration_path' => 'database/migrations/tenant_pos',
            // Koneksi sendiri supaya satu request bisa membuka tenant akuntansi
            // dan tenant POS bersamaan (jembatan POS -> Akuntansi, Fase 5).
            'connection' => 'pos',
        ],
    ],
];
