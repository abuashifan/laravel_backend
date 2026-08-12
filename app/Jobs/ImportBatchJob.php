<?php

namespace App\Jobs;

use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\ImportCommitterFactory;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
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
        TenantContext $tenantContext,
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
            $this->process($uuid, $companyId, $committers, $tenantContext, $tenantDatabase);
        } finally {
            $connections->disconnect();
        }
    }

    private function process(
        string $uuid,
        int $companyId,
        ImportCommitterFactory $committers,
        TenantContext $tenantContext,
        TenantDatabase $tenantDatabase,
    ): void {
        $batch = ImportBatch::query()->where('uuid', $uuid)->first();

        if (! $batch instanceof ImportBatch) {
            throw new \RuntimeException("Batch impor dengan uuid {$uuid} tidak ditemukan.");
        }

        // ImportBatchService::commit() sudah mengubah status batch ke 'committing'
        // secara sinkron SEBELUM men-dispatch job ini — jadi status yang diharapkan
        // di sini adalah 'committing', bukan 'previewed'.
        if ($batch->status !== 'committing') {
            throw new \RuntimeException("Batch impor harus berstatus 'committing', saat ini '{$batch->status}'.");
        }

        // Job berjalan di proses worker terpisah tanpa request HTTP, jadi
        // TenantContext (dipopulasi middleware EnsureCompanyAccess pada request
        // biasa) tidak pernah terisi di sini — service seperti JournalEntryService/
        // SalesInvoiceService/VendorBillService bergantung padanya untuk resolve
        // company aktif. Harus diisi manual dari company_id + pembuat batch.
        $company = Company::query()->find($companyId);

        if (! $company instanceof Company) {
            throw new \RuntimeException("Company dengan id {$companyId} tidak ditemukan.");
        }

        $companyUser = CompanyUser::query()
            ->where('company_id', $companyId)
            ->where('user_id', $batch->created_by)
            ->where('status', 'active')
            ->first();

        if (! $companyUser instanceof CompanyUser) {
            throw new \RuntimeException("Pembuat batch impor tidak lagi punya akses aktif ke company {$companyId}.");
        }

        $tenantContext->set($company, $companyUser, $tenantDatabase);

        if (! $committers->has($batch->profile)) {
            $this->markBatchFailed($uuid, "Profil impor '{$batch->profile}' belum memiliki committer.");

            return;
        }

        $committedCount = 0;

        try {
            $committer = $committers->make($batch->profile);
            $results = $committer->commit($batch);

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
            $errorMessage = null;
            if ($committedCount === 0) {
                $errorMessage = $this->summarizeCommitErrors($results);
            }

            $batch->update([
                'status' => $committedCount > 0 ? 'completed' : 'failed',
                'committed_rows' => $committedCount,
                'error_message' => $errorMessage,
            ]);
        } catch (Throwable $e) {
            $this->markBatchFailed($uuid, $e->getMessage(), $committedCount);

            throw $e;
        }
    }

    private function markBatchFailed(string $uuid, string $message, int $committedCount = 0): void
    {
        ImportBatch::query()->where('uuid', $uuid)->update([
            'status' => 'failed',
            'committed_rows' => $committedCount,
            'error_message' => $message,
        ]);

        logger()->error("ImportBatchJob gagal untuk batch {$uuid}: {$message}");
    }

    /**
     * Rangkum pesan error dari hasil commit yang gagal.
     *
     * @param  array<int, array{status: string, error: ?string}>  $results
     */
    private function summarizeCommitErrors(array $results): string
    {
        $errors = [];

        foreach ($results as $result) {
            $msg = $result['error'] ?? null;
            if ($msg === null) {
                continue;
            }

            $key = md5($msg);
            if (! isset($errors[$key])) {
                $errors[$key] = ['message' => $msg, 'count' => 0];
            }
            $errors[$key]['count']++;
        }

        if ($errors === []) {
            return 'Commit gagal — tidak ada baris yang berhasil di-commit.';
        }

        $lines = array_map(
            fn (array $e): string => "{$e['message']} ({$e['count']} baris)",
            array_values($errors),
        );

        return implode("\n", $lines);
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
