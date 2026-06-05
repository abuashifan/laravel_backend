<?php

namespace App\Services\Tenant;

use App\Models\TenantDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

class TenantConnectionManager
{
    public function connect(string|TenantDatabase $database): void
    {
        $databasePath = $database instanceof TenantDatabase
            ? $this->resolveDatabasePath($database)
            : $database;

        if (! File::exists($databasePath)) {
            throw new RuntimeException("Tenant database file does not exist at resolved path: {$databasePath}");
        }

        Config::set('database.connections.tenant.database', $databasePath);

        DB::purge('tenant');
        DB::reconnect('tenant');

        // Some CI/dev sandboxes restrict SQLite rollback journal file creation.
        // For tests we can safely keep SQLite journals in memory to avoid disk I/O errors.
        if (app()->environment('testing') && (string) config('database.connections.tenant.driver') === 'sqlite') {
            DB::connection('tenant')->statement('PRAGMA journal_mode = MEMORY');
            DB::connection('tenant')->statement('PRAGMA synchronous = OFF');
        }
    }

    public function disconnect(): void
    {
        DB::disconnect('tenant');
    }

    public function resolveDatabasePath(TenantDatabase $tenantDatabase): string
    {
        $tenantDirectory = (string) config('tenant.database_path', database_path('tenants'));
        $databaseName = trim((string) $tenantDatabase->database_name);
        $storedPath = trim((string) $tenantDatabase->database_path);
        $candidates = [];

        if ($storedPath !== '' && File::isFile($storedPath)) {
            $candidates[] = $storedPath;
        }

        if ($databaseName !== '') {
            $candidates[] = $tenantDirectory.DIRECTORY_SEPARATOR.basename($databaseName);
            $candidates[] = database_path('tenants/'.basename($databaseName));
        }

        if ($storedPath !== '') {
            $candidates[] = $tenantDirectory.DIRECTORY_SEPARATOR.basename($storedPath);
        }

        foreach ([$databaseName, basename($storedPath)] as $name) {
            if (preg_match('/^company_(\d+)\.sqlite$/', (string) $name, $matches) === 1) {
                $candidates[] = $tenantDirectory.DIRECTORY_SEPARATOR.'company_'.str_pad($matches[1], 6, '0', STR_PAD_LEFT).'.sqlite';
            }
        }

        foreach (array_unique(array_filter($candidates)) as $candidate) {
            if (File::isFile($candidate)) {
                return $candidate;
            }
        }

        $resolvedPath = $databaseName !== ''
            ? $tenantDirectory.DIRECTORY_SEPARATOR.basename($databaseName)
            : $storedPath;

        throw new RuntimeException(json_encode([
            'message' => 'Tenant database file is missing.',
            'company_id' => $tenantDatabase->company_id,
            'database_name' => $tenantDatabase->database_name,
            'database_path' => $tenantDatabase->database_path,
            'resolved_path' => $resolvedPath,
        ], JSON_UNESCAPED_SLASHES));
    }
}
