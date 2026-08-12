<?php

namespace App\Jobs;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\ImportCommitterFactory;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class ImportBatchJob implements ShouldQueue
{
    use Queueable;

    /**
     * Jumlah baris yang diproses sebelum memperbarui pencacah di import_batches.
     * Menulis 500 kali ke baris yang sama adalah pemborosan; 25 memberi progres
     * yang cukup sering tanpa membebani SQLite.
     */
    private const PROGRESS_INTERVAL = 25;

    public int $tries = 1;

    /**
     * @param  array{uuid: string, company_id: int}  $payload
     */
    public function __construct(private readonly array $payload) {}

    public function handle(
        TenantConnectionManager $connections,
        ImportCommitterFactory $committers,
    ): void {
        $uuid = (string) ($this->payload['uuid'] ?? '');
        $companyId = (int) ($this->payload['company_id'] ?? 0);

        if ($uuid === '' || $companyId === 0) {
            $this->fail(new \InvalidArgumentException('Payload job tidak lengkap: uuid dan company_id wajib diisi.'));

            return;
        }

        $tenantDatabase = TenantDatabase::query()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->first();

        if (! $tenantDatabase instanceof TenantDatabase) {
            $this->fail(new \RuntimeException("Tenant database untuk company_id {$companyId} tidak ditemukan atau tidak aktif."));

            return;
        }

        try {
            $connections->connect($tenantDatabase);
        } catch (Throwable $e) {
            $this->markBatchFailed($uuid, "Gagal menyambung ke database tenant: {$e->getMessage()}");

            return;
        }

        try {
            $this->process($uuid, $committers);
        } finally {
            $connections->disconnect();
        }
    }

    private function process(string $uuid, ImportCommitterFactory $committers): void
    {
        $batch = ImportBatch::query()->where('uuid', $uuid)->first();

        if (! $batch instanceof ImportBatch) {
            throw new \RuntimeException("Batch impor dengan uuid {$uuid} tidak ditemukan.");
        }

        if ($batch->status !== 'previewed') {
            throw new \RuntimeException("Batch impor harus berstatus 'previewed', saat ini '{$batch->status}'.");
        }

        if (! $committers->has($batch->profile)) {
            $this->markBatchFailed($uuid, "Profil impor '{$batch->profile}' belum memiliki committer.");

            return;
        }

        $batch->update(['status' => 'committing']);

        try {
            $committer = $committers->make($batch->profile);
            $results = $committer->commit($batch);

            $committedCount = 0;
            $processedCount = 0;

            foreach ($results as $rowId => $result) {
                $processedCount++;
                $committedCount += $result['status'] === 'committed' ? 1 : 0;

                ImportRow::query()->whereKey($rowId)->update([
                    'status' => $result['status'],
                    'document_id' => $result['document_id'],
                    'document_type' => $result['document_type'],
                    'errors' => $result['error'] !== null ? ['commit' => [$result['error']]] : null,
                ]);

                if ($processedCount % self::PROGRESS_INTERVAL === 0) {
                    $batch->update([
                        'committed_rows' => $committedCount,
                    ]);
                }
            }

            // Pembaruan akhir — pastikan pencacah selalu tepat di akhir.
            $batch->update([
                'status' => $committedCount > 0 ? 'completed' : 'failed',
                'committed_rows' => $committedCount,
            ]);
        } catch (Throwable $e) {
            $this->markBatchFailed($uuid, $e->getMessage());

            throw $e;
        }
    }

    private function markBatchFailed(string $uuid, string $message): void
    {
        ImportBatch::query()->where('uuid', $uuid)->update([
            'status' => 'failed',
        ]);

        logger()->error("ImportBatchJob gagal untuk batch {$uuid}: {$message}");
    }

    /**
     * Satu batch aktif per perusahaan — tolak job baru kalau masih ada
     * job untuk perusahaan yang sama sedang berjalan.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping((string) ($this->payload['company_id'] ?? 'unknown')),
        ];
    }
}
