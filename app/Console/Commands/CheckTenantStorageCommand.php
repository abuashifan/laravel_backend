<?php

namespace App\Console\Commands;

use App\Models\TenantDatabase;
use App\Services\Tenant\TenantConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckTenantStorageCommand extends Command
{
    protected $signature = 'tenant:check-storage';

    protected $description = 'Check tenant database storage directory';

    public function handle(TenantConnectionManager $connectionManager): int
    {
        $path = config('tenant.database_path');

        if (! is_dir($path)) {
            $this->error("Tenant directory does not exist: {$path}");
            return self::FAILURE;
        }

        if (! is_writable($path)) {
            $this->error("Tenant directory is not writable: {$path}");
            return self::FAILURE;
        }

        $this->info("Tenant directory is ready: {$path}");

        $tenantDatabases = TenantDatabase::query()
            ->where('status', 'active')
            ->orderBy('company_id')
            ->get();

        if ($tenantDatabases->isEmpty()) {
            $this->warn('No active tenant database records found.');
            return self::SUCCESS;
        }

        $requiredTables = ['chart_of_accounts', 'contacts', 'products', 'departments', 'projects'];
        $failed = false;

        foreach ($tenantDatabases as $tenantDatabase) {
            $this->newLine();
            $this->line('Company ID: '.$tenantDatabase->company_id);
            $this->line('Database name: '.$tenantDatabase->database_name);

            try {
                $resolvedPath = $connectionManager->resolveDatabasePath($tenantDatabase);
                $this->line('Resolved path: '.$resolvedPath);
                $connectionManager->connect($tenantDatabase);
            } catch (Throwable $e) {
                $failed = true;
                $this->error('Tenant database unavailable: '.$e->getMessage());
                continue;
            }

            try {
                foreach ($requiredTables as $table) {
                    if (! Schema::connection('tenant')->hasTable($table)) {
                        $failed = true;
                        $this->error("[MISSING] {$table}");
                        continue;
                    }

                    $count = DB::connection('tenant')->table($table)->count();
                    $line = "[OK] {$table}: {$count} rows";
                    if ($count === 0) {
                        $failed = true;
                        $this->warn($line);
                    } else {
                        $this->line($line);
                    }
                }
            } finally {
                $connectionManager->disconnect();
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
