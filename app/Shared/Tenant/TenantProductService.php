<?php

namespace App\Shared\Tenant;

use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\Storage\TenantStorage;
use App\Shared\Tenant\Storage\TenantStorageManager;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Produk ekosistem per perusahaan (Fase 0 POS).
 *
 * Perusahaan dan produk dipisah: perusahaan bisa punya hanya tenant
 * akuntansi, hanya tenant POS, atau keduanya. Mengaktifkan produk = membuat
 * wadah tenant bersuffix produk (`company_000001_pos.sqlite` / schema
 * `tenant_000001_pos`) lalu menjalankan folder migrasi produk itu.
 */
class TenantProductService
{
    public function __construct(
        private readonly TenantStorageManager $storages,
        private readonly TenantMigrationService $migrations,
        private readonly TenantStarterDataService $starterData,
    ) {}

    /** @return list<string> */
    public static function products(): array
    {
        return array_keys((array) config('tenant.products', []));
    }

    public static function assertKnown(string $product): void
    {
        if (! in_array($product, self::products(), true)) {
            throw new InvalidArgumentException("Produk tidak dikenal: {$product}");
        }
    }

    public function find(Company $company, string $product): ?TenantDatabase
    {
        return TenantDatabase::forProduct($product)->where('company_id', $company->id)->first();
    }

    /** @return list<string> produk yang tenant-nya aktif untuk perusahaan ini */
    public function activeProducts(Company $company): array
    {
        return TenantDatabase::allProducts()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->orderBy('product')
            ->pluck('product')
            ->map(fn ($product) => (string) $product)
            ->values()
            ->all();
    }

    /**
     * Idempoten: produk yang sudah aktif dikembalikan apa adanya. Kalau migrasi
     * gagal, wadah dan barisnya dibuang supaya bisa dicoba lagi bersih.
     */
    public function activate(Company $company, string $product): TenantDatabase
    {
        self::assertKnown($product);

        $existing = $this->find($company, $product);
        if ($existing && $existing->status === 'active') {
            return $existing;
        }

        $storage = $this->storages->default();
        $storage->assertReady();

        $name = $this->nameFor($storage, (int) $company->id, $product);
        $path = $storage->pathFor($name);

        $tenant = $existing ?? new TenantDatabase;
        $createdStorage = false;

        try {
            // Baris lama berstatus belum aktif boleh memakai ulang wadahnya;
            // tanpa baris, wadah yang sudah ada berarti milik sesuatu yang lain.
            if (! $existing || ! $storage->exists($name, $path)) {
                $storage->create($name, $path);
                $createdStorage = true;
            }

            $tenant->fill([
                'company_id' => $company->id,
                'product' => $product,
                'database_name' => $name,
                'database_path' => $path,
                'driver' => $storage->driver(),
                'status' => 'active',
            ])->save();

            $result = $this->migrations->migrateTenantDatabase($company, $tenant);
            if (! $result['success']) {
                throw new RuntimeException("Migrasi tenant {$product} gagal: ".($result['reason'] ?? 'unknown'));
            }

            $tenant->forceFill(['last_migrated_at' => now()])->save();

            if ($product === TenantDatabase::PRODUCT_ACCOUNTING) {
                $this->starterData->seed($tenant);
            }

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
    private function nameFor(TenantStorage $storage, int $companyId, string $product): string
    {
        $base = $storage->nameFor($companyId);
        $suffix = (string) config('tenant.products.'.$product.'.suffix', '_'.$product);
        if ($suffix === '') {
            return $base;
        }

        $extension = pathinfo($base, PATHINFO_EXTENSION);

        return $extension !== ''
            ? substr($base, 0, -strlen($extension) - 1).$suffix.'.'.$extension
            : $base.$suffix;
    }
}
