<?php

use Illuminate\Database\Migrations\Migration;

/*
 * Fase 3 hak akses per pengguna memindahkan akses peran lama (+ override
 * allow/deny) ke `company_user_permissions`. Migrasi ini sudah berjalan di
 * semua lingkungan (Render, lokal) pada 2026-10-28. Pada Fase 8 peran lama,
 * override, dan layanan penerjemahnya dihapus; database baru tidak punya
 * anggota berperan lama saat migrasi berjalan, jadi isinya dikosongkan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Sengaja kosong -- lihat catatan di atas.
    }

    public function down(): void
    {
        //
    }
};
