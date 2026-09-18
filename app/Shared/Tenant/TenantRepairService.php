<?php

namespace App\Shared\Tenant;

use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Pemulihan tenant database untuk company yang SUDAH ADA tapi file SQLite-nya
 * hilang — mis. kena wipe disk ephemeral saat deploy tanpa persistent disk
 * (lihat catatan di Dockerfile/deploy). Beda dari `TenantProvisioningService`:
 * itu untuk company BARU (bikin baris companies + company_users sekaligus);
 * ini cuma membetulkan file + baris `tenant_databases` untuk company yang
 * baris `companies`-nya sudah ada, dipanggil admin dari area client management.
 *
 * Data lama TIDAK bisa dipulihkan — file hilang berarti isinya hilang.
 * Hasilnya selalu tenant database kosong baru, siap dipakai ulang dari nol
 * persis seperti company baru saja dibuat (skema penuh lewat migration
 * tenant), bukan usaha memulihkan data yang sudah tidak ada.
 */
class TenantRepairService
{
    public function __construct(
        private readonly TenantMigrationService $migrationService,
    ) {}

    /**
     * @return array{success: bool, reason?: string, tenant_database?: TenantDatabase}
     */
    public function repair(Company $company): array
    {
        $tenantDirectory = config('tenant.database_path');
        if (! is_string($tenantDirectory) || $tenantDirectory === '') {
            return ['success' => false, 'reason' => 'Konfigurasi tenant.database_path tidak valid.'];
        }

        if (! File::isDirectory($tenantDirectory)) {
            throw new RuntimeException("Folder tenant database tidak ditemukan: {$tenantDirectory}");
        }

        if (! is_writable($tenantDirectory)) {
            return ['success' => false, 'reason' => "Folder tenant database tidak writable: {$tenantDirectory}"];
        }

        $tenantDatabase = TenantDatabase::query()->where('company_id', $company->id)->first();

        if (! $tenantDatabase) {
            $databaseName = 'company_'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT).'.sqlite';
            $databasePath = rtrim($tenantDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$databaseName;

            $tenantDatabase = TenantDatabase::query()->create([
                'company_id' => $company->id,
                'database_name' => $databaseName,
                'database_path' => $databasePath,
                'driver' => 'sqlite',
                'status' => 'active',
            ]);
        } else {
            // Baris sudah ada — path kanonik ditulis ulang (folder tenant bisa
            // saja berubah antar deploy) dan status dipastikan aktif lagi
            // kalau sebelumnya sempat ditandai bermasalah.
            $databaseName = basename((string) $tenantDatabase->database_name);
            $databasePath = rtrim($tenantDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$databaseName;

            $tenantDatabase->forceFill([
                'database_path' => $databasePath,
                'status' => 'active',
            ])->save();
        }

        // File lama (kalau somehow masih ada tapi rusak) dibuang dulu — hasil
        // akhirnya selalu file kosong baru.
        if (File::exists($databasePath)) {
            File::delete($databasePath);
        }
        File::put($databasePath, '');

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
}
