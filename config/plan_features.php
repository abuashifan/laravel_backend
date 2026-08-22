<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Saklar penegakan
    |--------------------------------------------------------------------------
    |
    | Fase 1 memasang mesinnya dengan saklar mati. Fase 2 mengisi `plans.features`
    | (lihat `PlanSeeder`) dengan peta tier yang disetujui dan menyalakan saklar
    | ini — sesudahnya ada client yang kehilangan akses ke kemampuan yang
    | sebelumnya terbuka. Urutan peluncurannya (beri paket ke setiap client dulu,
    | baru nyalakan) ada di `phase-2-peta-tier-dan-peluncuran.md` §7.
    |
    */

    'enforce' => env('PLAN_FEATURES_ENFORCE', true),

    /*
    |--------------------------------------------------------------------------
    | Peta fitur → izin
    |--------------------------------------------------------------------------
    |
    | DAFTAR PUTIH, BUKAN DAFTAR HITAM. Izin yang tidak disebut di sini selalu
    | terbuka. Konsekuensinya disengaja: menambah modul baru tidak diam-diam
    | mematikannya di semua tier — modul baru harus didaftarkan secara sadar
    | kalau memang mau digerbangi.
    |
    | Nama fitur di kiri dicocokkan dengan isi kolom `plans.features` (JSON
    | array of string). Nilai di kanan adalah kunci izin; akhiran `.*`
    | mencocokkan seluruh kunci yang berawalan itu.
    |
    | HANYA IZIN TULIS. Client yang turun tier tetap boleh MEMBACA data yang
    | terlanjur dibuatnya — client Enterprise yang sudah menandai 2.000 jurnal
    | dengan departemen lalu turun ke Pro tidak boleh kehilangan angka itu dari
    | pandangan. Jadi `budgets.view`, `warehouses.view`, `departments.view`,
    | `projects.view`, `access.roles.view`, `access.permissions.view`, dan
    | `reports.view` sengaja TIDAK ada di sini.
    |
    | `access.users.*` dan `access.invitations.*` juga sengaja terbuka di semua
    | tier: Basic dengan 3 user tetap harus bisa mengundang orang. Yang membatasi
    | jumlahnya adalah kuota user, bukan gerbang fitur.
    |
    | Satu kunci fitur TIDAK ada di peta ini: `transaction_approval` (mode
    | pengaturan `draft_approve_post`). Itu bukan izin, melainkan NILAI
    | pengaturan — digerbangi langsung di `UpdateCompanyAccountingSettingRequest`
    | lewat `PlanPermissionResolver::featuresFor()`, bukan lewat peta ini. Lihat
    | "Alur persetujuan transaksi" di dokumen Fase 2.
    |
    */

    'features' => [

        // --- Pro ke atas ---------------------------------------------------

        'transaction_import' => [
            'transactions.import',
        ],

        'multi_warehouse' => [
            'warehouses.create',
            'warehouses.edit',
            'warehouses.deactivate',
        ],

        // BUKAN `audit.*`. Kunci itu ada di katalog izin dan di preset role
        // finance/accountant, tapi tidak ada satu rute pun yang memakainya
        // sebagai gerbang (diverifikasi Fase 2: `grep -rn "permission:audit\."
        // app/` kosong) — sisa desain lama yang tidak pernah disambungkan.
        // Rute jejak audit yang benar-benar hidup (`GET /access/audit`,
        // `AccessAuditController`) dijaga `access.audit.view`. Pengecualian
        // dari aturan "hanya izin tulis": isinya catatan sistem, bukan data
        // yang dibuat client, jadi tidak ada yang hilang dari pandangan saat
        // tier turun.
        'audit_trail' => [
            'access.audit.view',
        ],

        'advanced_reports' => [
            'reports.save',
            'reports.multi_period',
        ],

        // --- Enterprise ----------------------------------------------------

        'budgeting' => [
            'budgets.submit',
            'budgets.approve_head',
            'budgets.approve_finance',
            'budgets.manage',
            'budgets.revise',
            'budgets.export',
            // `budgets.view` sengaja TIDAK di-gate: akses baca harus bertahan
            // saat paket diturunkan.
        ],

        'dimensions' => [
            'departments.create',
            'departments.edit',
            'departments.deactivate',
            'projects.create',
            'projects.edit',
            'projects.deactivate',
        ],

        // Role kustom per perusahaan. Melihat role preset yang berlaku
        // (`access.roles.view`) tetap terbuka di semua tier.
        'user_permission' => [
            'access.roles.create',
            'access.roles.edit',
            'access.roles.clone',
            'access.roles.deactivate',
            'access.roles.manage',
            'access.permissions.assign',
            'access.permissions.revoke',
            'access.permissions.manage',
        ],

    ],

];
