<?php

namespace App\Console\Commands;

use App\Shared\Models\TenantDatabase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pengukuran harian untuk kuota penyimpanan (Fase 4, skema tier). Bukan
 * tiap request — itu pemborosan. `StorageQuotaService` membaca angka yang
 * ditulis command ini; boleh basi sampai satu hari, sejalan dengan §"Kapan
 * diperiksa" di rencana.
 *
 * Yang diukur: ukuran berkas sqlite tenant + seluruh berkas impor tersimpan
 * untuk perusahaan itu (`storage/app/private/imports/{company_id}/`).
 */
class MeasureTenantStorageCommand extends Command
{
    protected $signature = 'storage:measure';

    protected $description = 'Ukur ukuran penyimpanan tiap tenant (sqlite + berkas impor) untuk kuota penyimpanan';

    public function handle(): int
    {
        $tenants = TenantDatabase::query()->where('status', 'active')->get();

        if ($tenants->isEmpty()) {
            $this->info('Tidak ada tenant aktif.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($tenants as $tenant) {
            $sqliteBytes = is_file($tenant->database_path) ? (int) filesize($tenant->database_path) : 0;
            $importBytes = $this->importFilesSize((int) $tenant->company_id);
            $totalBytes = $sqliteBytes + $importBytes;

            $tenant->forceFill([
                'size_bytes' => $totalBytes,
                'measured_at' => now(),
            ])->save();

            $rows[] = [
                $tenant->company_id,
                $tenant->database_name,
                $this->formatMb($sqliteBytes),
                $this->formatMb($importBytes),
                $this->formatMb($totalBytes),
            ];
        }

        $this->table(['Company ID', 'Tenant DB', 'SQLite (MB)', 'Impor (MB)', 'Total (MB)'], $rows);
        $this->info(sprintf('Selesai. %d tenant diukur.', $tenants->count()));

        return self::SUCCESS;
    }

    private function importFilesSize(int $companyId): int
    {
        $disk = Storage::disk('local');
        $directory = 'imports/'.$companyId;

        if (! $disk->exists($directory)) {
            return 0;
        }

        $total = 0;
        foreach ($disk->allFiles($directory) as $file) {
            $total += (int) $disk->size($file);
        }

        return $total;
    }

    private function formatMb(int $bytes): string
    {
        return number_format($bytes / 1024 / 1024, 2);
    }
}
