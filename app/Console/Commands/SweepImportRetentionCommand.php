<?php

namespace App\Console\Commands;

use App\Modules\Imports\Models\ImportBatch;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Subscription\StorageQuotaService;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Masa simpan berkas impor per tier (Fase 4, skema tier) — Basic/Pro 30 hari,
 * Enterprise 90 hari, Custom manual. Menghapus batch impor beserta berkas
 * fisiknya yang lebih tua dari masa simpan tier client itu; `import_rows`
 * ikut terhapus lewat cascade di skema tenant.
 *
 * CAKUPAN SAAT INI TERBATAS ke status `failed` — satu-satunya status
 * terminal yang sungguhan ada hari ini. Modul Imports masih "Fase 0":
 * `commit()` sendiri melempar "belum tersedia" (lihat
 * `ImportBatchService::commit()`), dan `cancel()` menghapus baris + berkas
 * SEKETIKA, bukan menandainya `cancelled` untuk disimpan sampai masa
 * berlaku habis. Begitu commit sungguhan ada dan punya status akhir
 * (`committed`), tambahkan ke daftar status di bawah — jangan tebak nama
 * status yang belum ditulis modul itu.
 */
class SweepImportRetentionCommand extends Command
{
    protected $signature = 'imports:sweep-retention {--dry-run : Cetak daftar tanpa menghapus apa pun}';

    protected $description = 'Hapus batch impor + berkasnya yang sudah melewati masa simpan tier client';

    private const TERMINAL_STATUSES = ['failed'];

    public function handle(TenantConnectionManager $connectionManager, StorageQuotaService $storageQuota): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $companies = Company::query()
            ->whereHas('tenantDatabase', fn ($q) => $q->where('status', 'active'))
            ->with('tenantDatabase')
            ->get();

        if ($companies->isEmpty()) {
            $this->info('Tidak ada perusahaan dengan tenant aktif.');

            return self::SUCCESS;
        }

        $rows = [];
        $totalDeleted = 0;

        foreach ($companies as $company) {
            /** @var TenantDatabase $tenantDatabase */
            $tenantDatabase = $company->tenantDatabase;
            $retentionDays = $storageQuota->retentionDaysFor($company);
            $cutoff = now()->subDays($retentionDays);

            $connectionManager->connect($tenantDatabase);

            $stale = ImportBatch::query()
                ->whereIn('status', self::TERMINAL_STATUSES)
                ->where('created_at', '<', $cutoff)
                ->get();

            if ($stale->isEmpty()) {
                continue;
            }

            foreach ($stale as $batch) {
                if (! $dryRun) {
                    Storage::disk('local')->delete($batch->stored_path);
                    $batch->delete();
                }
                $totalDeleted++;
            }

            $rows[] = [$company->id, $company->name, $retentionDays, $stale->count()];
        }

        $connectionManager->disconnect();

        if ($dryRun) {
            $this->warn('--dry-run: tidak ada berkas atau baris yang benar-benar dihapus.');
        }

        $this->table(['Company ID', 'Perusahaan', 'Masa simpan (hari)', 'Batch dihapus'], $rows);
        $this->info(sprintf('Selesai. %d batch impor dihapus.', $totalDeleted));

        return self::SUCCESS;
    }
}
