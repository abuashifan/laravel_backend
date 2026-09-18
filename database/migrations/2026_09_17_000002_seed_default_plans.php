<?php

use Database\Seeders\PlanSeeder;
use Illuminate\Database\Migrations\Migration;

/**
 * Bootstrap satu kali: isi tabel `plans` dengan paket standar (Free, Basic,
 * Pro, Enterprise, Custom).
 *
 * `PlanSeeder` hanya jalan lewat `php artisan db:seed`, dan itu tidak
 * ter-autorun saat deploy (Dockerfile cuma autorun `migrate` dan
 * `storage:link`) — server ini juga tidak punya akses shell untuk
 * menjalankannya manual. Migration ini numpang di mekanisme autorun
 * migration yang sudah ada supaya seeding tetap kejadian tanpa shell.
 *
 * Aman dijalankan ulang: `PlanSeeder::run()` memakai `updateOrCreate`
 * per kode plan, dan migration sendiri tercatat selesai sekali di tabel
 * `migrations` sehingga tidak akan otomatis terpanggil ulang di deploy
 * berikutnya (jadi tidak menimpa perubahan harga/fitur yang nanti diubah
 * manual dari database).
 *
 * Sengaja skip di `testing`: `RefreshDatabase` menjalankan migration ini
 * juga, dan banyak test (`tests/Feature/Admin`, `Companies`, `Subscription`)
 * sengaja bikin plan sendiri dengan `code` yang sama (mis. 'free', 'pro')
 * mengasumsikan tabel `plans` kosong sehabis migrate. Tanpa guard ini,
 * insert mereka bentrok UNIQUE constraint dengan baris yang sudah diseed
 * migration ini — bukan skenario yang mau diuji migration ini sama sekali.
 * Test yang MEMANG mau plan standar sudah punya jalurnya sendiri:
 * `TestCase::seedTierPlans()` memanggil `PlanSeeder` langsung.
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
        // Sengaja tidak ada rollback — menghapus baris `plans` lewat rollback
        // migration berisiko memutus relasi `users.plan_id` client yang sudah
        // aktif memakainya.
    }
};
