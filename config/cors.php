<?php

return [

    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
    ],

    'allowed_methods' => ['*'],

    /*
     | Origin frontend yang diizinkan. Diisi dari env CORS_ALLOWED_ORIGINS
     | (comma-separated) agar produksi tidak perlu mengubah kode.
     | Contoh produksi: CORS_ALLOWED_ORIGINS=https://app.finlite.my.id
     | Default (dev): localhost:3000 + Vite localhost:5173.
     */
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'CORS_ALLOWED_ORIGINS',
            'http://localhost:3000,http://127.0.0.1:3000,http://localhost:5173,http://127.0.0.1:5173',
        )),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
