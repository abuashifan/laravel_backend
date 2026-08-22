<?php

namespace App\Console\Commands;

use App\Shared\Models\User;
use Illuminate\Console\Command;

/**
 * Admin aplikasi pertama tidak bisa lahir dari GUI-nya sendiri, dan menaikkan
 * seseorang jadi admin adalah jalur eskalasi hak paling berbahaya di sistem
 * ini — karena itu hanya lewat sini, yang menuntut akses ke server.
 */
class MakePlatformAdminCommand extends Command
{
    protected $signature = 'user:make-admin
        {--email= : Email user yang dijadikan admin aplikasi}
        {--revoke : Cabut status admin aplikasi dari user ini}';

    protected $description = 'Tandai user sebagai admin aplikasi (pengelola client)';

    public function handle(): int
    {
        $email = (string) ($this->option('email') ?? '');

        if (trim($email) === '') {
            $email = (string) $this->ask('Email user');
        }

        $user = User::query()->where('email', trim($email))->first();

        if (! $user) {
            $this->error('User tidak ditemukan.');

            return self::FAILURE;
        }

        $revoke = (bool) $this->option('revoke');

        if (! $revoke && $user->companyUsers()->exists()) {
            // Batas "admin aplikasi tidak melihat data keuangan client" jadi
            // kabur kalau akunnya juga anggota sebuah perusahaan.
            $this->error('User ini masih terdaftar di sebuah perusahaan. Pakai akun terpisah untuk admin aplikasi.');

            return self::FAILURE;
        }

        $user->forceFill(['is_platform_admin' => ! $revoke])->save();

        // Sesi lama dicabut: ability token tidak berubah sendiri saat flagnya
        // berubah, jadi user harus masuk lewat pintu yang sesuai.
        $user->tokens()->delete();

        $this->info($revoke ? 'Status admin aplikasi dicabut.' : 'User ditandai sebagai admin aplikasi.');
        $this->newLine();
        $this->line('User ID: '.$user->id);
        $this->line('Email: '.$user->email);
        $this->line('Platform admin: '.($user->is_platform_admin ? 'ya' : 'tidak'));
        $this->newLine();
        $this->line('Semua sesi user ini dicabut. Masuk lewat halaman login admin.');

        return self::SUCCESS;
    }
}
