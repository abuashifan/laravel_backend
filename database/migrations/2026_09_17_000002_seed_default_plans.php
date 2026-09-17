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
 */
return new class extends Migration
{
    public function up(): void
    {
        (new PlanSeeder())->run();
    }

    public function down(): void
    {
        // Sengaja tidak ada rollback — menghapus baris `plans` lewat rollback
        // migration berisiko memutus relasi `users.plan_id` client yang sudah
        // aktif memakainya.
    }
};
