<?php

namespace App\Services\Tenant;

use App\Models\Company;
use App\Models\TenantDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Throwable;

class TenantMigrationService
{
    public function __construct(private readonly TenantConnectionManager $connectionManager)
    {
    }

    /**
     * @return array{
     *   success: bool,
     *   reason?: string,
     *   company?: Company,
     *   tenant_database?: TenantDatabase,
     *   exit_code?: int,
     *   output?: string,
     * }
     */
    public function migrateCompany(int $companyId): array
    {
        $company = Company::query()->find($companyId);
        if (! $company) {
            return ['success' => false, 'reason' => 'Company tidak ditemukan.'];
        }

        if (isset($company->status) && ! in_array($company->status, ['active', 'trial'], true)) {
            return ['success' => false, 'reason' => 'Company tidak aktif.'];
        }

        $tenantDatabase = TenantDatabase::query()->where('company_id', $company->id)->first();
        if (! $tenantDatabase) {
            return ['success' => false, 'reason' => 'Tenant database tidak ditemukan.'];
        }

        if (isset($tenantDatabase->status) && $tenantDatabase->status !== 'active') {
            return ['success' => false, 'reason' => 'Tenant database belum aktif.'];
        }

        return $this->migrateTenantDatabase($company, $tenantDatabase);
    }

    /**
     * @return array{
     *   total: int,
     *   success: int,
     *   failed: int,
     *   results: array<int, array{
     *     company_id: int,
     *     database_name: string,
     *     success: bool,
     *     reason?: string
     *   }>
     * }
     */
    public function migrateAllActive(): array
    {
        $tenantDatabases = TenantDatabase::query()
            ->where('status', 'active')
            ->get();

        $results = [];
        $successCount = 0;
        $failedCount = 0;

        foreach ($tenantDatabases as $tenantDatabase) {
            $company = Company::query()->find($tenantDatabase->company_id);
            $databaseName = (string) $tenantDatabase->database_name;

            if (! $company) {
                $failedCount++;
                $results[] = [
                    'company_id' => (int) $tenantDatabase->company_id,
                    'database_name' => $databaseName,
                    'success' => false,
                    'reason' => 'Company tidak ditemukan.',
                ];
                continue;
            }

            if (isset($company->status) && ! in_array($company->status, ['active', 'trial'], true)) {
                $failedCount++;
                $results[] = [
                    'company_id' => (int) $company->id,
                    'database_name' => $databaseName,
                    'success' => false,
                    'reason' => 'Company tidak aktif.',
                ];
                continue;
            }

            if (isset($tenantDatabase->status) && $tenantDatabase->status !== 'active') {
                $failedCount++;
                $results[] = [
                    'company_id' => (int) $company->id,
                    'database_name' => $databaseName,
                    'success' => false,
                    'reason' => 'Tenant database belum aktif.',
                ];
                continue;
            }

            $result = $this->migrateTenantDatabase($company, $tenantDatabase);

            if ($result['success']) {
                $successCount++;
                $results[] = [
                    'company_id' => (int) $company->id,
                    'database_name' => $databaseName,
                    'success' => true,
                ];
            } else {
                $failedCount++;
                $results[] = [
                    'company_id' => (int) $company->id,
                    'database_name' => $databaseName,
                    'success' => false,
                    'reason' => $result['reason'] ?? 'Unknown error',
                ];
            }
        }

        return [
            'total' => $tenantDatabases->count(),
            'success' => $successCount,
            'failed' => $failedCount,
            'results' => $results,
        ];
    }

    /**
     * @return array{
     *   success: bool,
     *   reason?: string,
     *   company: Company,
     *   tenant_database: TenantDatabase,
     *   exit_code?: int,
     *   output?: string
     * }
     */
    private function migrateTenantDatabase(Company $company, TenantDatabase $tenantDatabase): array
    {
        $databaseName = (string) ($tenantDatabase->database_name ?? '');
        if (trim($databaseName) === '') {
            return [
                'success' => false,
                'reason' => 'database_name tidak boleh kosong.',
                'company' => $company,
                'tenant_database' => $tenantDatabase,
            ];
        }

        $databasePath = database_path('tenants/'.$databaseName);
        try {
            $databasePath = $this->connectionManager->resolveDatabasePath($tenantDatabase);
        } catch (Throwable) {
            // Keep the canonical path in the failure result below.
        }

        $migrationPath = base_path('database/migrations/tenant');
        if (! File::isDirectory($migrationPath)) {
            return [
                'success' => false,
                'reason' => 'Migration path database/migrations/tenant tidak ditemukan.',
                'company' => $company,
                'tenant_database' => $tenantDatabase,
            ];
        }

        if (! File::exists($databasePath)) {
            return [
                'success' => false,
                'reason' => 'File SQLite tenant tidak ditemukan.',
                'company' => $company,
                'tenant_database' => $tenantDatabase,
            ];
        }

        if (! is_writable($databasePath)) {
            return [
                'success' => false,
                'reason' => 'File SQLite tenant tidak writable.',
                'company' => $company,
                'tenant_database' => $tenantDatabase,
            ];
        }

        try {
            $this->connectionManager->connect($tenantDatabase);

            $exitCode = Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations/tenant',
                '--force' => true,
            ]);

            $output = Artisan::output();

            if ($exitCode !== 0) {
                return [
                    'success' => false,
                    'reason' => 'Migration gagal (non-zero exit code).',
                    'company' => $company,
                    'tenant_database' => $tenantDatabase,
                    'exit_code' => $exitCode,
                    'output' => $output,
                ];
            }

            return [
                'success' => true,
                'company' => $company,
                'tenant_database' => $tenantDatabase,
                'exit_code' => $exitCode,
                'output' => $output,
            ];
        } catch (Throwable $e) {
            return [
                'success' => false,
                'reason' => $e->getMessage(),
                'company' => $company,
                'tenant_database' => $tenantDatabase,
            ];
        } finally {
            try {
                $this->connectionManager->disconnect();
            } catch (Throwable $e) {
                // ignore disconnect errors
            }
        }
    }
}
