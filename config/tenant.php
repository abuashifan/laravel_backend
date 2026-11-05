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

    'database_path' => database_path('tenants'),

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
            'suffix' => '',
            'migration_path' => 'database/migrations/tenant',
        ],
        'pos' => [
            'suffix' => '_pos',
            'migration_path' => 'database/migrations/tenant_pos',
        ],
    ],
];
