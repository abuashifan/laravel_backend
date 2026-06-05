<?php

namespace App\Services\Tenant;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TenantProvisioningService
{
    /**
     * @return array{
     *   company: Company,
     *   owner: User,
     *   tenant_database: TenantDatabase,
     *   company_user: CompanyUser,
     *   database_name: string,
     *   database_path: string,
     *   tenant_directory: string
     * }
     */
    public function provision(string $name, string $slug, string $ownerEmail): array
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

        if (!filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Owner email tidak valid.');
        }

        $owner = User::query()->where('email', $ownerEmail)->first();
        if (!$owner) {
            throw new InvalidArgumentException('Owner email tidak ditemukan di tabel users.');
        }

        if (Company::query()->where('slug', $slug)->exists()) {
            throw new InvalidArgumentException('Company slug sudah digunakan.');
        }

        $tenantDirectory = config('tenant.database_path');
        if (!is_string($tenantDirectory) || $tenantDirectory === '') {
            throw new RuntimeException('Konfigurasi tenant.database_path tidak valid.');
        }

        if (!File::isDirectory($tenantDirectory)) {
            throw new RuntimeException("Folder tenant database tidak ditemukan: {$tenantDirectory}");
        }

        if (!is_writable($tenantDirectory)) {
            throw new RuntimeException("Folder tenant database tidak writable: {$tenantDirectory}");
        }

        $createdTenantFilePath = null;

        try {
            return DB::transaction(function () use ($name, $slug, $owner, $tenantDirectory, &$createdTenantFilePath) {
                $tempCode = 'TMP-'.Str::uuid()->toString();

                $company = Company::query()->create([
                    'name' => $name,
                    'slug' => $slug,
                    'code' => $tempCode,
                    'status' => 'active',
                    'created_by' => $owner->id,
                ]);

                $companyCode = 'CMP-'.str_pad((string) $company->id, 6, '0', STR_PAD_LEFT);
                $company->forceFill(['code' => $companyCode])->save();

                $databaseName = $this->generateDatabaseName($company->id);
                $databasePath = database_path('tenants/'.$databaseName);

                if (TenantDatabase::query()->where('database_name', $databaseName)->exists()) {
                    throw new RuntimeException("Generated database_name sudah ada di tenant_databases: {$databaseName}");
                }

                if (File::exists($databasePath)) {
                    throw new RuntimeException("File tenant database sudah ada: {$databasePath}");
                }

                File::put($databasePath, '');
                $createdTenantFilePath = $databasePath;

                $tenantDatabase = TenantDatabase::query()->create([
                    'company_id' => $company->id,
                    'database_name' => $databaseName,
                    'database_path' => $databasePath,
                    'driver' => 'sqlite',
                    'status' => 'active',
                ]);

                $companyUser = CompanyUser::query()->create([
                    'company_id' => $company->id,
                    'user_id' => $owner->id,
                    'role' => 'owner',
                    'status' => 'active',
                    'joined_at' => now(),
                ]);

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
            if ($createdTenantFilePath && File::exists($createdTenantFilePath)) {
                File::delete($createdTenantFilePath);
            }

            throw $e;
        }
    }

    private function generateDatabaseName(int $companyId): string
    {
        $prefix = (string) config('tenant.database_prefix', 'company_');
        $extension = (string) config('tenant.database_extension', '.sqlite');

        return $prefix.str_pad((string) $companyId, 6, '0', STR_PAD_LEFT).$extension;
    }
}

