<?php

namespace App\Modules\Pos\Services;

use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\Storage\TenantStorage;
use App\Shared\Tenant\Storage\TenantStorageManager;
use App\Shared\Tenant\TenantMigrationService;
use RuntimeException;
use Throwable;

/**
 * Mengaktifkan Finlite POS untuk satu perusahaan: membuat tenant POS
 * tersendiri (berkas SQLite / schema Postgres bersuffix `_pos`) lalu
 * menjalankan migrasi `database/migrations/tenant_pos`.
 */
class PosActivationService
{
    public function __construct(
        private readonly TenantStorageManager $storages,
        private readonly TenantMigrationService $migrations,
    ) {}

    public function find(Company $company): ?TenantDatabase
    {
        return TenantDatabase::forProduct(TenantDatabase::PRODUCT_POS)
            ->where('company_id', $company->id)
            ->first();
    }

    public function activate(Company $company): TenantDatabase
    {
        $existing = $this->find($company);
        if ($existing && $existing->status === 'active') {
            return $existing;
        }

        $storage = $this->storages->default();
        $storage->assertReady();

        $name = $this->nameFor($storage, (int) $company->id);
        $path = $storage->pathFor($name);

        $tenant = $existing ?? new TenantDatabase;
        $createdStorage = false;

        try {
            if (! $storage->exists($name, $path)) {
                $storage->create($name, $path);
                $createdStorage = true;
            }

            $tenant->fill([
                'company_id' => $company->id,
                'product' => TenantDatabase::PRODUCT_POS,
                'database_name' => $name,
                'database_path' => $path,
                'driver' => $storage->driver(),
                'status' => 'active',
            ])->save();

            $result = $this->migrations->migrateTenantDatabase($company, $tenant);
            if (! $result['success']) {
                throw new RuntimeException('Migrasi tenant POS gagal: '.($result['reason'] ?? 'unknown'));
            }

            $tenant->forceFill(['last_migrated_at' => now()])->save();

            return $tenant;
        } catch (Throwable $e) {
            if ($createdStorage) {
                try {
                    $storage->drop($name, $path);
                } catch (Throwable) {
                    // Kegagalan pembersihan tidak boleh menutupi error aslinya.
                }
            }
            if ($tenant->exists) {
                $tenant->delete();
            }

            throw $e;
        }
    }

    /** `company_000001.sqlite` -> `company_000001_pos.sqlite`; `tenant_000001` -> `tenant_000001_pos`. */
    private function nameFor(TenantStorage $storage, int $companyId): string
    {
        $base = $storage->nameFor($companyId);
        $suffix = (string) config('tenant.products.pos.suffix', '_pos');
        $extension = pathinfo($base, PATHINFO_EXTENSION);

        return $extension !== ''
            ? substr($base, 0, -strlen($extension) - 1).$suffix.'.'.$extension
            : $base.$suffix;
    }
}
