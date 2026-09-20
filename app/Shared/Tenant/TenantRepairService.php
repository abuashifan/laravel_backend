<?php

namespace App\Shared\Tenant;

use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\Storage\TenantStorageManager;

/**
 * Pemulihan tenant database untuk company yang SUDAH ADA tapi wadah datanya
 * hilang — berkas SQLite kena wipe disk ephemeral saat deploy, atau schema
 * Postgres terhapus. Beda dari `TenantProvisioningService`: itu untuk company
 * BARU (bikin baris companies + company_users sekaligus); ini cuma membetulkan
 * wadah + baris `tenant_databases` untuk company yang baris `companies`-nya
 * sudah ada, dipanggil admin dari area client management.
 *
 * Data lama TIDAK bisa dipulihkan — wadah hilang berarti isinya hilang.
 * Hasilnya selalu tenant database kosong baru, siap dipakai ulang dari nol
 * persis seperti company baru saja dibuat (skema penuh lewat migration tenant),
 * bukan usaha memulihkan data yang sudah tidak ada.
 */
class TenantRepairService
{
    public function __construct(
        private readonly TenantMigrationService $migrationService,
        private readonly TenantStorageManager $storages,
    ) {}

    /**
     * @return array{success: bool, reason?: string, tenant_database?: TenantDatabase}
     */
    public function repair(Company $company): array
    {
        $tenantDatabase = TenantDatabase::query()->where('company_id', $company->id)->first();

        // Perbaikan SELALU memakai driver yang berlaku sekarang, bukan driver
        // yang tercatat di baris lama. Ini yang menjadikan tombol "Buat Ulang
        // Database" sekaligus jalur pindah dari SQLite ke Postgres: tenant lama
        // bertanda `sqlite` datanya memang sudah hilang (berkasnya ikut terhapus
        // saat deploy), jadi membangunnya ulang sebagai berkas ephemeral cuma
        // mengulang masalah yang sama. Yang dibangun adalah wadah kosong dengan
        // penyimpanan yang benar, lalu barisnya ikut dipindahkan.
        $storage = $this->storages->default();

        try {
            $storage->assertReady();
        } catch (\RuntimeException $e) {
            return ['success' => false, 'reason' => $e->getMessage()];
        }

        if (! $tenantDatabase) {
            $databaseName = $storage->nameFor($company->id);
            $databasePath = $storage->pathFor($databaseName);

            $tenantDatabase = TenantDatabase::query()->create([
                'company_id' => $company->id,
                'database_name' => $databaseName,
                'database_path' => $databasePath,
                'driver' => $storage->driver(),
                'status' => 'active',
            ]);
        } elseif ($this->driverOf($tenantDatabase) === $storage->driver()) {
            // Driver tidak berubah: nama yang sudah tercatat DIPERTAHANKAN.
            // Menggantinya dengan nama kanonik akan memindahkan tenant ke berkas
            // lain tanpa alasan, dan meninggalkan berkas lama sebagai yatim.
            // Yang ditulis ulang hanya lokasinya (folder tenant bisa berpindah
            // antar lingkungan) dan statusnya.
            $databaseName = $storage->driver() === 'sqlite'
                ? basename((string) $tenantDatabase->database_name)
                : (string) $tenantDatabase->database_name;
            $databasePath = $storage->pathFor($databaseName);

            $tenantDatabase->forceFill([
                'database_name' => $databaseName,
                'database_path' => $databasePath,
                'status' => 'active',
            ])->save();
        } else {
            // Driver berpindah — inilah jalur SQLite → Postgres. Wadah lama
            // dibuang selagi barisnya masih menyimpan driver dan lokasi aslinya,
            // kalau tidak berkas SQLite-nya tertinggal yatim setelah baris ini
            // menunjuk ke schema.
            $this->dropPrevious($tenantDatabase);

            $databaseName = $storage->nameFor($company->id);
            $databasePath = $storage->pathFor($databaseName);

            $tenantDatabase->forceFill([
                'database_name' => $databaseName,
                'database_path' => $databasePath,
                'driver' => $storage->driver(),
                'status' => 'active',
            ])->save();
        }

        // Wadah lama (kalau somehow masih ada tapi rusak) dibuang dulu — hasil
        // akhirnya selalu wadah kosong baru.
        $storage->drop($databaseName, $databasePath);
        $storage->create($databaseName, $databasePath);

        $migration = $this->migrationService->migrateCompany($company->id);

        if (! ($migration['success'] ?? false)) {
            return [
                'success' => false,
                'reason' => 'Migrasi tenant gagal: '.($migration['reason'] ?? 'Unknown error'),
                'tenant_database' => $tenantDatabase,
            ];
        }

        return ['success' => true, 'tenant_database' => $tenantDatabase->refresh()];
    }

    /**
     * Buang wadah tenant versi sebelumnya, memakai driver yang tercatat di
     * barisnya — bukan driver yang berlaku sekarang. Dipanggil sebelum baris
     * ditulis ulang, jadi nilai lamanya masih utuh.
     *
     * Kegagalan di sini tidak menggagalkan perbaikan: datanya memang sudah
     * dianggap hilang, dan wadah yatim jauh lebih ringan dampaknya daripada
     * perusahaan yang tidak bisa dipakai sama sekali.
     */
    /** Driver yang tercatat di baris, dengan `sqlite` sebagai nilai bawaan. */
    private function driverOf(TenantDatabase $tenantDatabase): string
    {
        $driver = trim((string) $tenantDatabase->driver);

        return $driver === '' ? 'sqlite' : $driver;
    }

    private function dropPrevious(TenantDatabase $tenantDatabase): void
    {
        try {
            $this->storages->driver($this->driverOf($tenantDatabase))->drop(
                (string) $tenantDatabase->database_name,
                (string) $tenantDatabase->database_path,
            );
        } catch (\Throwable) {
            // Driver lama bisa saja tidak dikenal lagi, atau wadahnya memang
            // sudah lenyap — keduanya bukan alasan membatalkan perbaikan.
        }
    }
}
