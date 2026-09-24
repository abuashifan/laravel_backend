<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    /*
     * Domain yang boleh memanggil API ini dari browser. Dibaca dari
     * `CORS_ALLOWED_ORIGINS` (dipisah koma) supaya menambah domain -- staging,
     * domain baru, host frontend yang berpindah -- cukup mengubah environment
     * variable lalu restart, bukan mengubah kode dan menunggu deploy ulang.
     *
     * Defaultnya sengaja sama persis dengan daftar yang dulu ditulis di sini,
     * jadi tanpa variabel itu perilakunya tidak berubah sama sekali.
     *
     * Salah setel di sini tidak terlihat di log server: permintaannya diblokir
     * browser sebelum sampai, dan gejalanya menyerupai server mati (aplikasi
     * terbuka, semua panggilan gagal). Errornya hanya muncul di Console browser.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:3000,http://127.0.0.1:3000,https://app.finlite.my.id,https://react-frontend-three-eta.vercel.app',
        )),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
