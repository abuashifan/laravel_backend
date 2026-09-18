<?php

use Database\Seeders\PlanSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Revisi kebijakan: satu perusahaan di semua tier bertingkat (Free/Basic/
 * Pro/Enterprise) — pembedanya jumlah user & fitur, bukan jumlah perusahaan.
 * Pro sebelumnya 3, Enterprise sebelumnya 5 (lihat migration
 * `2026_09_17_000002_seed_default_plans.php` dan dokumen keputusan awal di
 * Finlite_knowladge/plans/subscription-tiers/phase-2-peta-tier-dan-peluncuran.md
 * §1 — keputusan itu sudah tidak berlaku, digantikan revisi ini).
 *
 * `PlanSeeder` sendiri sudah diperbarui (satu-satunya tempat angkanya
 * ditulis), migration ini cuma memicu re-apply ke baris yang sudah ada di
 * production — migration awal yang menyeed plan sudah tercatat selesai jadi
 * tidak jalan ulang otomatis walau isi seeder-nya berubah. Aman dijalankan
 * ulang: `updateOrCreate` per kode plan.
 *
 * Custom TIDAK tersentuh perubahan ini — kuotanya sudah dan tetap manual
 * per client lewat `users.company_quota`.
 *
 * Sengaja skip di `testing`, alasan sama dengan migration seed plan
 * sebelumnya (`2026_09_17_000002_seed_default_plans.php`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        (new PlanSeeder())->run();
    }

    public function down(): void
    {
        // Sengaja tidak ada rollback — lihat alasan yang sama di migration
        // seed plan sebelumnya.
    }
};
