<?php

namespace App\Console\Commands;

use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\Storage\TenantStorageManager;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class CheckTenantStorageCommand extends Command
{
    protected $signature = 'tenant:check-storage';

    protected $description = 'Check tenant database storage readiness';

    public function __construct(private readonly TenantStorageManager $storages)
    {
        parent::__construct();
    }

    public function handle(TenantConnectionManager $connectionManager): int
    {
        // Pemeriksaan kesiapan penyimpanan diserahkan ke driver yang berlaku:
        // folder writable untuk SQLite, koneksi hidup untuk Postgres. Dulu
        // bagian ini selalu memeriksa folder, sehingga command ini gagal di
        // production yang tenant-nya sama sekali tidak memakai berkas.
        $storage = $this->storages->default();

        try {
            $storage->assertReady();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Penyimpanan tenant siap (driver: %s).', $storage->driver()));

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
