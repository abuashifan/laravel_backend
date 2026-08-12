<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Langganan pindah dari per-perusahaan ke per-client (Fase 3, skema tier).
 *
 * `subscriptions.company_id` mengasumsikan satu langganan per perusahaan;
 * kenyataannya paket menempel di client (`users.plan_id`) sejak kuota
 * dibangun. Client Pro dengan 3 perusahaan membeli SATU langganan yang
 * mencakup ketiganya, bukan tiga langganan terpisah. Tabelnya efektif kosong
 * (dua baris demo trial/free dari `DemoCentralSeeder`), jadi ini waktu
 * termurah mengubahnya — lihat phase-3-siklus-langganan.md §2a & §3.
 *
 * Bertahap, BUKAN drop-and-create: tabel ini bisa saja sudah berisi data
 * nyata di lingkungan yang tidak terlihat dari migrasi ini.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Tambah user_id (nullable dulu, diisi di langkah 2).
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')
                ->constrained('users')->restrictOnDelete();
        });

        // 2. Isi dari companies.created_by lewat company_id — pemilik
        // perusahaan adalah pemilik langganannya.
        DB::statement('
            UPDATE subscriptions
            SET user_id = (
                SELECT companies.created_by FROM companies WHERE companies.id = subscriptions.company_id
            )
        ');

        // 3. Baris trial/free adalah nilai yang sudah tidak ada di model
        // bisnis baru (tidak ada trial, Free dinonaktifkan) — dibuang, bukan
        // dipetakan ke siklus baru yang tidak pernah mereka jalani.
        DB::table('subscriptions')
            ->where('billing_cycle', 'free')
            ->orWhere('status', 'trial')
            ->delete();

        // 4. Baris yang company-nya sudah tidak punya pemilik (created_by
        // NULL) tidak bisa dipetakan ke client mana pun.
        DB::table('subscriptions')->whereNull('user_id')->delete();

        Schema::table('subscriptions', function (Blueprint $table) {
            // 5. user_id wajib, company_id dibuang. Indeks lama company_id/status
            // dibuang lebih dulu — SQLite tidak melepasnya sendiri saat kolom
            // yang diindeksnya dihapus.
            $table->dropIndex(['company_id']);
            $table->dropIndex(['status']);
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
            $table->foreignId('user_id')->nullable(false)->change();

            // 6. Tidak ada trial di model baru.
            $table->dropColumn('trial_ends_at');

            // 8. Harus diisi eksplisit — tidak ada lagi bawaan 'free'.
            $table->string('billing_cycle')->change();

            // 9. Status diturunkan dari tanggal (starts_at/ends_at/cancelled_at),
            // bukan disimpan — lihat SubscriptionService::stateFor(). Kalau
            // status disimpan dan digeser command harian, command yang gagal
            // jalan semalam berarti orang terkunci atau terbuka secara keliru.
            $table->dropColumn('status');

            $table->index('user_id');
            $table->index('ends_at');
        });

        // 7. starts_at/ends_at non-nullable — dilakukan TERAKHIR, setelah baris
        // tanpa tanggal (trial lama) sudah dibuang di langkah 3.
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestamp('starts_at')->nullable(false)->change();
            $table->timestamp('ends_at')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropIndex(['ends_at']);
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');

            $table->foreignId('company_id')->nullable()->after('id')
                ->constrained('companies')->cascadeOnDelete();
            $table->timestamp('trial_ends_at')->nullable();
            $table->string('status')->default('trial');
            $table->string('billing_cycle')->default('free')->change();
            $table->timestamp('starts_at')->nullable()->change();
            $table->timestamp('ends_at')->nullable()->change();
        });
    }
};
