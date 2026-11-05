<?php

namespace App\Shared\Tenant;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Tenant\Storage\TenantStorageManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TenantProvisioningService
{
    public function __construct(private readonly TenantStorageManager $storages) {}

    /**
     * @return array{
     *   company: Company,
     *   owner: User,
     *   tenant_database: TenantDatabase|null,
     *   company_user: CompanyUser,
     *   database_name: string,
     *   database_path: string,
     *   tenant_directory: string
     * }
     */
    /**
     * `$withAccountingTenant = false` membuat perusahaan beserta pemiliknya saja,
     * tanpa tenant akuntansi -- dipakai perusahaan yang hanya memakai produk
     * lain (mis. POS). Tenant produk lalu dibuat `TenantProductService::activate()`.
     */
    public function provision(string $name, string $slug, string $ownerEmail, bool $withAccountingTenant = true): array
    {
        $name = trim($name);
        $slug = trim($slug);
        $ownerEmail = trim($ownerEmail);

        if ($name === '') {
            throw new InvalidArgumentException('Company name wajib diisi.');
        }

        if ($slug === '') {
            throw new InvalidArgumentException('Company slug wajib diisi.');
        }

        if ($ownerEmail === '') {
            throw new InvalidArgumentException('Owner email wajib diisi.');
        }

        if (! filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Owner email tidak valid.');
        }

        $owner = User::query()->where('email', $ownerEmail)->first();
        if (! $owner) {
            throw new InvalidArgumentException('Owner email tidak ditemukan di tabel users.');
        }

        if (Company::query()->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException('Company slug sudah digunakan.');
        }

        $storage = $this->storages->default();

        if (! $withAccountingTenant) {
            return DB::transaction(function () use ($name, $slug, $owner) {
                $company = $this->createCompany($name, $slug, $owner);

                return [
                    'company' => $company,
                    'owner' => $owner,
                    'tenant_database' => null,
                    'company_user' => $this->createOwnerMembership($company, $owner),
                    'database_name' => '',
                    'database_path' => '',
                    'tenant_directory' => (string) config('tenant.database_path'),
                ];
            });
        }

        // Kesiapan penyimpanan diperiksa SEBELUM baris apa pun ditulis. Kalau
        // folder tenant tidak writable atau Postgres tidak terjangkau, lebih
        // baik gagal di sini daripada meninggalkan company tanpa tenant.
        $storage->assertReady();

        $tenantDirectory = (string) config('tenant.database_path');
        $createdTenant = null;

        try {
            return DB::transaction(function () use ($name, $slug, $owner, $storage, $tenantDirectory, &$createdTenant) {
                $company = $this->createCompany($name, $slug, $owner);

                $databaseName = $storage->nameFor($company->id);
                $databasePath = $storage->pathFor($databaseName);

                if (TenantDatabase::query()->where('database_name', $databaseName)->exists()) {
                    throw new RuntimeException("Generated database_name sudah ada di tenant_databases: {$databaseName}");
                }

                $storage->create($databaseName, $databasePath);
                $createdTenant = [$storage, $databaseName, $databasePath];

                $tenantDatabase = TenantDatabase::query()->create([
                    'company_id' => $company->id,
                    'database_name' => $databaseName,
                    'database_path' => $databasePath,
                    'driver' => $storage->driver(),
                    'status' => 'active',
                ]);

                $companyUser = $this->createOwnerMembership($company, $owner);

                return [
                    'company' => $company,
                    'owner' => $owner,
                    'tenant_database' => $tenantDatabase,
                    'company_user' => $companyUser,
                    'database_name' => $databaseName,
                    'database_path' => $databasePath,
                    'tenant_directory' => $tenantDirectory,
                ];
            });
        } catch (Throwable $e) {
            // Wadah tenant hidup di luar transaksi database central (berkas di
            // disk, atau schema yang CREATE-nya tidak ikut rollback), jadi
            // pembersihannya harus eksplisit.
            if ($createdTenant !== null) {
                [$usedStorage, $usedName, $usedPath] = $createdTenant;

                try {
                    $usedStorage->drop($usedName, $usedPath);
                } catch (Throwable) {
                    // Kegagalan pembersihan tidak boleh menutupi error aslinya.
                }
            }

            throw $e;
        }
    }

    private function createCompany(string $name, string $slug, User $owner): Company
    {
        $company = Company::query()->create([
            'name' => $name,
            'slug' => $slug,
            'code' => 'TMP-'.Str::uuid()->toString(),
            'status' => 'active',
            'created_by' => $owner->id,
        ]);

        $company->forceFill(['code' => 'CMP-'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT)])->save();

        return $company;
    }

    private function createOwnerMembership(Company $company, User $owner): CompanyUser
    {
        return CompanyUser::query()->create([
            'company_id' => $company->id,
            'user_id' => $owner->id,
            'role' => 'owner',
            'full_access' => true,
            'is_owner' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }
}
