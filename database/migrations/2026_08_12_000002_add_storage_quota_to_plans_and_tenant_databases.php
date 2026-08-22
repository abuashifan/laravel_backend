<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuota penyimpanan (Fase 4, skema tier) — pengganti `max_transactions_per_month`
 * yang dibuang. Diukur dari ukuran berkas sqlite tenant + berkas impor yang
 * tersimpan, bukan jumlah transaksi. Lihat `phase-4-kuota-penyimpanan.md`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            // MB, bukan byte — angkanya kecil dan gampang dibaca manusia di
            // seeder/area admin. Dikonversi ke byte di StorageQuotaService.
            $table->unsignedInteger('storage_quota_mb')->default(1024)->after('max_transactions_per_month');
            $table->unsignedSmallInteger('import_retention_days')->default(30)->after('storage_quota_mb');
        });

        Schema::table('users', function (Blueprint $table) {
            // Pola yang sama dengan company_quota/user_quota (Fase 0): NULL
            // berarti ikut paket, angka berarti kuota khusus tier Custom.
            $table->unsignedInteger('storage_quota_mb')->nullable()->after('extra_users');
            $table->unsignedSmallInteger('import_retention_days')->nullable()->after('storage_quota_mb');
        });

        Schema::table('tenant_databases', function (Blueprint $table) {
            // `size_bytes` sudah ada sejak Mei tapi tidak pernah diisi siapa
            // pun. `measured_at` menandai kapan angkanya terakhir benar —
            // tanpanya tidak ada cara membedakan "0 byte karena baru dibuat"
            // dari "belum pernah diukur sama sekali".
            $table->timestamp('measured_at')->nullable()->after('size_bytes');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['storage_quota_mb', 'import_retention_days']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['storage_quota_mb', 'import_retention_days']);
        });

        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->dropColumn('measured_at');
        });
    }
};
