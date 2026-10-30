<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * Fase 8 hak akses per pengguna: akses kini sepenuhnya melekat ke anggota
 * (`company_user_permissions`, `full_access`, `is_owner`). Peran preset,
 * izin per peran, dan override allow/deny sudah tidak dibaca di mana pun.
 * Kolom `company_users.role` dipertahankan sebagai label tanpa fungsi
 * otorisasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_users', 'role_id')) {
            Schema::table('company_users', function ($table): void {
                $table->dropConstrainedForeignId('role_id');
            });
        }

        Schema::dropIfExists('company_user_permission_overrides');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }

    public function down(): void
    {
        // Tidak dikembalikan: data peran lama sudah dipindah ke izin langsung pada Fase 3.
    }
};
