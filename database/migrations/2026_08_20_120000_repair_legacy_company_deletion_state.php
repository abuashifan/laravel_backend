<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Perbaikan data untuk perusahaan yang terlanjur dihapus versi awal
 * `CompanyDeletionService`.
 *
 * Versi itu ikut menimpa `company_users.status` jadi `removed` dan
 * `tenant_databases.status` jadi `deleted`. Keduanya sekarang tidak lagi
 * disentuh saat menghapus (soft delete di `companies` sudah cukup), tapi baris
 * yang terlanjur tertimpa akan membuat pemulihannya cacat: perusahaan kembali
 * muncul, namun ownernya tidak bisa membukanya karena `select()` menolak
 * tenant yang tidak aktif.
 *
 * Sasarannya sengaja disempitkan ke perusahaan yang SEDANG terhapus dengan
 * penanda `tenant_databases.status = 'deleted'` — tanda tangan khas kode lama
 * itu. Tenant yang dinonaktifkan lewat jalur lain tidak ikut tersentuh.
 */
return new class extends Migration
{
    public function up(): void
    {
        $legacyCompanyIds = DB::table('companies')
            ->join('tenant_databases', 'tenant_databases.company_id', '=', 'companies.id')
            ->whereNotNull('companies.deleted_at')
            ->where('tenant_databases.status', 'deleted')
            ->pluck('companies.id');

        if ($legacyCompanyIds->isEmpty()) {
            return;
        }

        DB::table('tenant_databases')
            ->whereIn('company_id', $legacyCompanyIds)
            ->update(['status' => 'active']);

        // Hanya baris `removed` yang dikembalikan. Status lain pada perusahaan
        // yang sama (mis. staf yang memang dinonaktifkan owner sebelum
        // penghapusan) dibiarkan apa adanya.
        DB::table('company_users')
            ->whereIn('company_id', $legacyCompanyIds)
            ->where('status', 'removed')
            ->update(['status' => 'active']);
    }

    /**
     * Tidak bisa dibalik: status asli sebelum ditimpa kode lama memang tidak
     * pernah tersimpan di mana pun — itulah cacat yang diperbaiki di sini.
     */
    public function down(): void {}
};
