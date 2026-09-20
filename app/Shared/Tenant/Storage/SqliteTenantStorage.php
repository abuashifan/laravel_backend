<?php

namespace App\Shared\Tenant\Storage;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Satu berkas `.sqlite` per perusahaan di dalam `config('tenant.database_path')`.
 *
 * Dipakai test suite dan pengembangan lokal. TIDAK cocok untuk production di
 * container tanpa disk permanen — berkasnya ikut terhapus setiap deploy, yang
 * justru alasan `PostgresTenantStorage` ada.
 */
class SqliteTenantStorage implements TenantStorage
{
    public function driver(): string
    {
        return 'sqlite';
    }

    public function nameFor(int $companyId): string
    {
        $prefix = (string) config('tenant.database_prefix', 'company_');
        $extension = (string) config('tenant.database_extension', '.sqlite');

        return $prefix.str_pad((string) $companyId, 6, '0', STR_PAD_LEFT).$extension;
    }

    public function pathFor(string $name): string
    {
        return rtrim($this->directory(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($name);
    }

    public function assertReady(): void
    {
        $directory = $this->directory();

        if (! File::isDirectory($directory)) {
            throw new RuntimeException("Folder tenant database tidak ditemukan: {$directory}");
        }

        if (! is_writable($directory)) {
            throw new RuntimeException("Folder tenant database tidak writable: {$directory}");
        }
    }

    public function create(string $name, string $path): void
    {
        if (File::exists($path)) {
            throw new RuntimeException("File tenant database sudah ada: {$path}");
        }

        File::put($path, '');
    }

    public function exists(string $name, string $path): bool
    {
        return $this->locate($name, $path) !== null;
    }

    public function drop(string $name, string $path): bool
    {
        $resolved = $this->locate($name, $path);

        return $resolved !== null && File::delete($resolved);
    }

    public function sizeBytes(string $name, string $path): ?int
    {
        $resolved = $this->locate($name, $path);
        if ($resolved === null) {
            return null;
        }

        $size = @filesize($resolved);

        return $size === false ? null : (int) $size;
    }

    public function connect(string $name, string $path): void
    {
        $resolved = $this->locate($name, $path);

        if ($resolved === null) {
            throw new RuntimeException("Tenant database file does not exist at resolved path: {$path}");
        }

        Config::set('database.connections.tenant.driver', 'sqlite');
        Config::set('database.connections.tenant.database', $resolved);
        Config::set('database.connections.tenant.url', null);
        Config::set('database.connections.tenant.search_path', null);

        DB::purge('tenant');
        DB::reconnect('tenant');

        // Sebagian sandbox CI/dev melarang pembuatan berkas journal rollback.
        // Di test journal ditahan di memori supaya tidak menyentuh disk.
        if (app()->environment('testing')) {
            DB::connection('tenant')->statement('PRAGMA journal_mode = MEMORY');
            DB::connection('tenant')->statement('PRAGMA synchronous = OFF');

            return;
        }

        // WAL membiarkan pembaca dan penulis jalan bersamaan — pola akses saat
        // worker antrean menulis sementara user bekerja di browser. busy_timeout
        // memberi ruang tunggu 5 detik sebelum SQLITE_BUSY.
        DB::connection('tenant')->statement('PRAGMA journal_mode = WAL');
        DB::connection('tenant')->statement('PRAGMA busy_timeout = 5000');
    }

    private function directory(): string
    {
        $directory = config('tenant.database_path', database_path('tenants'));

        if (! is_string($directory) || $directory === '') {
            throw new RuntimeException('Konfigurasi tenant.database_path tidak valid.');
        }

        return $directory;
    }

    /**
     * Cari berkas tenant di beberapa kemungkinan lokasi.
     *
     * Toleransi ini disengaja: folder tenant bisa berpindah antar lingkungan
     * (dev vs container), dan baris lama bisa menyimpan path absolut dari mesin
     * lain. Nama berkas yang sama di folder yang berlaku sekarang tetap dipakai
     * daripada dianggap hilang.
     */
    private function locate(string $name, string $path): ?string
    {
        $directory = $this->directory();
        $name = trim($name);
        $path = trim($path);
        $candidates = [];

        if ($path !== '') {
            $candidates[] = $path;
        }

        if ($name !== '') {
            $candidates[] = $directory.DIRECTORY_SEPARATOR.basename($name);
            $candidates[] = database_path('tenants/'.basename($name));
        }

        if ($path !== '') {
            $candidates[] = $directory.DIRECTORY_SEPARATOR.basename($path);
        }

        foreach ([$name, $path === '' ? '' : basename($path)] as $candidateName) {
            if (preg_match('/^company_(\d+)\.sqlite$/', (string) $candidateName, $matches) === 1) {
                $candidates[] = $directory.DIRECTORY_SEPARATOR.'company_'.str_pad($matches[1], 6, '0', STR_PAD_LEFT).'.sqlite';
            }
        }

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            if (File::isFile($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
