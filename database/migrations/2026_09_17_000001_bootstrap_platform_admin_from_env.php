<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstrap satu kali: buat/naikkan platform admin pertama lewat env var,
 * bukan lewat GUI (yang memang sengaja tidak menyediakan jalur ini — lihat
 * MakePlatformAdminCommand). Dipakai karena environment deploy ini tidak
 * punya akses shell untuk jalankan `artisan user:make-admin` manual.
 *
 * Set PLATFORM_ADMIN_EMAIL dan PLATFORM_ADMIN_PASSWORD di environment
 * variable server (Render dashboard) sebelum deploy. Migration ini no-op
 * kalau salah satu env var kosong, dan aman dijalankan ulang (idempotent).
 *
 * Setelah admin berhasil dibuat dan bisa login, SEGERA hapus kedua env var
 * ini dari dashboard Render — migration tidak akan jalan ulang lagi karena
 * sudah tercatat selesai di tabel `migrations`, jadi tidak ada gunanya
 * dibiarkan tersimpan di sana.
 */
return new class extends Migration
{
    public function up(): void
    {
        $email = trim((string) env('PLATFORM_ADMIN_EMAIL', ''));
        $password = (string) env('PLATFORM_ADMIN_PASSWORD', '');

        if ($email === '' || $password === '') {
            return;
        }

        $now = now();

        $existing = DB::table('users')->where('email', $email)->first();

        if ($existing) {
            DB::table('users')->where('id', $existing->id)->update([
                'password' => Hash::make($password),
                'is_platform_admin' => true,
                'status' => 'active',
                'updated_at' => $now,
            ]);

            return;
        }

        DB::table('users')->insert([
            'name' => 'Platform Admin',
            'email' => $email,
            'password' => Hash::make($password),
            'status' => 'active',
            'is_platform_admin' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Sengaja tidak ada rollback — menghapus akun admin lewat migration
        // rollback terlalu berisiko (bisa terpicu tanpa sengaja saat deploy
        // bermasalah). Pencabutan admin lewat `user:make-admin --revoke`.
    }
};
