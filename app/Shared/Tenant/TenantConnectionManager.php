<?php

namespace App\Shared\Tenant;

use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\Storage\TenantStorageManager;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mengarahkan koneksi `tenant` ke satu perusahaan.
 *
 * Sejak tenant bisa disimpan sebagai berkas SQLite ATAU schema Postgres, kelas
 * ini tidak lagi tahu-menahu soal berkas: seluruh urusan penyimpanan didelegasikan
 * ke `TenantStorage` sesuai kolom `driver` milik tenant tersebut. Yang tersisa di
 * sini hanya kontrak yang sudah dipakai lintas aplikasi (middleware, job, command).
 */
class TenantConnectionManager
{
    public function __construct(private readonly TenantStorageManager $storages) {}

    public function connect(string|TenantDatabase $database): void
    {
        if ($database instanceof TenantDatabase) {
            $this->storages->for($database)->connect(
                (string) $database->database_name,
                (string) $database->database_path,
            );

            return;
        }

        // Bentuk string hanya bermakna untuk SQLite: pemanggil lama menyerahkan
        // path berkas secara langsung. Postgres selalu lewat TenantDatabase
        // karena butuh nama schema, bukan lokasi di disk.
        $this->storages->driver('sqlite')->connect(basename($database), $database);
    }

    public function disconnect(): void
    {
        DB::disconnect('tenant');
    }

    /**
     * Lokasi tenant seperti yang dipakai penyimpanannya: path berkas untuk
     * SQLite, nama schema untuk Postgres. Dipakai untuk diagnosa dan tampilan
     * di command, bukan untuk operasi berkas langsung.
     *
     * @throws RuntimeException kalau tenant-nya tidak ada
     */
    public function resolveDatabasePath(TenantDatabase $tenantDatabase): string
    {
        $storage = $this->storages->for($tenantDatabase);
        $name = (string) $tenantDatabase->database_name;
        $path = (string) $tenantDatabase->database_path;

        if (! $storage->exists($name, $path)) {
            throw new RuntimeException(json_encode([
                'message' => 'Tenant database is missing.',
                'company_id' => $tenantDatabase->company_id,
                'driver' => $storage->driver(),
                'database_name' => $tenantDatabase->database_name,
                'database_path' => $tenantDatabase->database_path,
            ], JSON_UNESCAPED_SLASHES));
        }

        return $storage->driver() === 'sqlite'
            ? $this->existingSqlitePath($tenantDatabase)
            : $name;
    }

    /**
     * Path berkas SQLite yang benar-benar ada. Folder tenant bisa berpindah
     * antar lingkungan, jadi nilai di kolom belum tentu masih berlaku.
     */
    private function existingSqlitePath(TenantDatabase $tenantDatabase): string
    {
        $storage = $this->storages->driver('sqlite');
        $name = basename((string) $tenantDatabase->database_name);
        $stored = (string) $tenantDatabase->database_path;

        foreach ([$stored, $storage->pathFor($name)] as $candidate) {
            if ($candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return $storage->pathFor($name);
    }
}
