<?php

namespace App\Shared\Tenant\Storage;

use App\Shared\Models\TenantDatabase;
use InvalidArgumentException;

/**
 * Memilih penyimpanan yang tepat untuk sebuah tenant.
 *
 * Driver ditentukan per baris `tenant_databases`, bukan global. Itu disengaja:
 * saat migrasi dari berkas SQLite ke schema Postgres, kedua jenis tenant hidup
 * berdampingan di satu database central sampai yang lama selesai dipindah.
 * Tenant baru memakai `config('tenant.driver')`; tenant lama tetap dibaca
 * dengan driver yang tercatat di barisnya sendiri.
 */
class TenantStorageManager
{
    public function __construct(
        private readonly SqliteTenantStorage $sqlite,
        private readonly PostgresTenantStorage $postgres,
    ) {}

    /** Penyimpanan untuk tenant yang akan DIBUAT. */
    public function default(): TenantStorage
    {
        return $this->driver((string) config('tenant.driver', 'sqlite'));
    }

    /** Penyimpanan untuk tenant yang SUDAH ADA, mengikuti driver di barisnya. */
    public function for(TenantDatabase $tenantDatabase): TenantStorage
    {
        $driver = trim((string) $tenantDatabase->driver);

        return $this->driver($driver === '' ? 'sqlite' : $driver);
    }

    public function driver(string $driver): TenantStorage
    {
        return match ($driver) {
            'sqlite' => $this->sqlite,
            'pgsql', 'postgres', 'postgresql' => $this->postgres,
            default => throw new InvalidArgumentException("Driver tenant tidak dikenal: {$driver}"),
        };
    }
}
