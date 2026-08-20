<?php

namespace App\Console\Commands;

use App\Shared\Company\CompanyPurgeService;
use App\Shared\Models\Company;
use Illuminate\Console\Command;

/**
 * Masa pemulihan perusahaan terhapus (default 30 hari, lihat
 * `config/companies.php`). Lewat dari itu, perusahaan dihapus permanen
 * beserta file database tenant-nya dan tidak bisa dipulihkan lagi.
 *
 * Jadwalkan harian lewat cron:
 *   php artisan companies:sweep-deleted
 *
 * Sebelum menjalankan sungguhan, `--dry-run` memperlihatkan apa saja yang
 * akan hilang tanpa menyentuh satu baris pun.
 */
class SweepDeletedCompaniesCommand extends Command
{
    protected $signature = 'companies:sweep-deleted
        {--dry-run : Cetak daftar tanpa menghapus apa pun}
        {--days= : Timpa masa pemulihan dari config (hari)}';

    protected $description = 'Hapus permanen perusahaan yang sudah lewat masa pemulihan beserta database tenant-nya';

    public function handle(CompanyPurgeService $purgeService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('companies.deletion_retention_days', 30);

        $due = $purgeService->dueForPurge($days);

        if ($due->isEmpty()) {
            $this->info(sprintf('Tidak ada perusahaan terhapus yang melewati %d hari.', $days));

            return self::SUCCESS;
        }

        $rows = [];
        $purged = 0;

        foreach ($due as $company) {
            /** @var Company $company */
            $rows[] = [
                $company->id,
                $company->name,
                $company->deleted_at?->toDateTimeString(),
                (int) floor((float) $company->deleted_at?->diffInDays(now())).' hari lalu',
            ];

            if (! $dryRun) {
                $purgeService->purge($company);
                $purged++;
            }
        }

        $this->table(['Company ID', 'Perusahaan', 'Dihapus pada', 'Umur'], $rows);

        if ($dryRun) {
            $this->warn(sprintf(
                '--dry-run: %d perusahaan DI ATAS akan dihapus permanen. Tidak ada yang diubah.',
                $due->count(),
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf('Selesai. %d perusahaan dihapus permanen beserta file tenant-nya.', $purged));

        return self::SUCCESS;
    }
}
