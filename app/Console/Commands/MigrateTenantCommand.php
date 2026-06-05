<?php

namespace App\Console\Commands;

use App\Services\Tenant\TenantMigrationService;
use Illuminate\Console\Command;

class MigrateTenantCommand extends Command
{
    protected $signature = 'tenant:migrate
        {--company-id= : Migrate a single tenant by company_id}
        {--all : Migrate all active tenants}';

    protected $description = 'Run tenant migrations (internal only)';

    public function handle(TenantMigrationService $migrationService): int
    {
        $companyIdOption = $this->option('company-id');
        $all = (bool) $this->option('all');

        if (($companyIdOption === null || $companyIdOption === '') && ! $all) {
            $this->error('Gunakan --company-id=ID atau --all.');
            return self::FAILURE;
        }

        if (($companyIdOption !== null && $companyIdOption !== '') && $all) {
            $this->error('Tidak boleh memakai --company-id dan --all bersamaan.');
            return self::FAILURE;
        }

        if ($all) {
            $summary = $migrationService->migrateAllActive();

            if ($summary['total'] === 0) {
                $this->error('Tidak ada tenant aktif untuk dimigrasi.');
                return self::FAILURE;
            }

            $this->info('Tenant migration completed.');
            $this->newLine();
            $this->line('Total tenants: '.$summary['total']);
            $this->line('Success: '.$summary['success']);
            $this->line('Failed: '.$summary['failed']);
            $this->newLine();

            foreach ($summary['results'] as $result) {
                if ($result['success']) {
                    $this->line('[OK] Company ID '.$result['company_id'].' - '.$result['database_name']);
                } else {
                    $this->line('[FAILED] Company ID '.$result['company_id'].' - '.$result['database_name']);
                    $this->line('Reason: '.($result['reason'] ?? 'Unknown error'));
                }
            }

            return $summary['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        if (! is_numeric($companyIdOption) || (int) $companyIdOption <= 0) {
            $this->error('company_id wajib numeric/integer.');
            return self::FAILURE;
        }

        $companyId = (int) $companyIdOption;
        $result = $migrationService->migrateCompany($companyId);

        if (! $result['success']) {
            $this->error($result['reason'] ?? 'Tenant migration failed.');
            return self::FAILURE;
        }

        $company = $result['company'];
        $tenantDatabase = $result['tenant_database'];

        $this->info('Tenant migration completed successfully.');
        $this->newLine();
        $this->line('Company ID: '.$company->id);
        $this->line('Company Name: '.$company->name);
        $this->line('Tenant Database: '.$tenantDatabase->database_name);
        $this->line('Migration Path: database/migrations/tenant');

        return self::SUCCESS;
    }
}

