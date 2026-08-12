<?php

namespace App\Modules\Imports\Services;

use App\Jobs\ImportBatchJob;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\ImportCommitterFactory;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Exceptions\ApiException;
use App\Shared\Subscription\StorageQuotaService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportBatchService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly SpreadsheetReaderFactory $readerFactory,
        private readonly StorageQuotaService $storageQuota,
        private readonly ImportCommitterFactory $committers,
    ) {}

    public function upload(string $profile, UploadedFile $file, bool $confirmDuplicateFile = false): array
    {
        $this->ensureProfileExists($profile);
        $this->ensureNoActiveBatch();
        $this->ensureStorageQuotaAvailable($file->getSize());

        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $reader = $this->readerFactory->make($extension);
        $path = $file->getRealPath();

        if ($path === false) {
            throw $this->fileError('Berkas impor tidak bisa dibaca.');
        }

        $fileHash = hash_file('sha256', $path);
        $duplicate = $this->duplicateFile($profile, $fileHash);

        if ($duplicate !== null && ! $confirmDuplicateFile) {
            throw new DuplicateImportFileException($duplicate);
        }

        [$headers, $totalRows] = $this->inspect($reader, $path);
        $this->assertHeadersAndRowCount($headers, $totalRows);

        $uuid = (string) Str::uuid();
        $storedPath = 'imports/'.$this->companyId().'/'.$uuid.'.'.$extension;

        if (! Storage::disk('local')->put($storedPath, file_get_contents($path))) {
            throw $this->fileError('Berkas impor gagal disimpan.');
        }

        $batch = ImportBatch::query()->create([
            'uuid' => $uuid,
            'profile' => $profile,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'file_hash' => $fileHash,
            'status' => 'draft',
            'total_rows' => $totalRows,
            'valid_rows' => 0,
            'failed_rows' => 0,
            'committed_rows' => 0,
            'created_by' => auth()->id(),
        ]);

        return [
            'batch' => $this->batchPayload($batch),
            'headers' => $headers,
            'duplicate_file' => $duplicate,
        ];
    }

    public function applyMapping(string $uuid, array $columnMap): array
    {
        $batch = $this->find($uuid);
        $path = Storage::disk('local')->path($batch->stored_path);
        $extension = strtolower(pathinfo($batch->stored_path, PATHINFO_EXTENSION));
        $reader = $this->readerFactory->make($extension);
        $normalizedMap = $this->normalizeColumnMap($columnMap);
        $requiredFields = $this->requiredFields($batch->profile);

        try {
            $headers = $reader->headers($path);
            $missingHeaders = array_values(array_diff(array_values($normalizedMap), $headers));

            if ($missingHeaders !== []) {
                throw ApiException::make(
                    ApiErrorCode::VALIDATION_ERROR,
                    'Pemetaan kolom berisi header yang tidak ada di berkas.',
                    422,
                    ['column_map' => array_map(fn (string $header): string => "Header {$header} tidak ditemukan.", $missingHeaders)]
                );
            }

            DB::connection('tenant')->transaction(function () use ($batch, $reader, $path, $normalizedMap, $requiredFields): void {
                $batch->update([
                    'status' => 'validating',
                    'column_map' => $normalizedMap,
                    'valid_rows' => 0,
                    'failed_rows' => 0,
                ]);
                $batch->rows()->delete();

                $validRows = 0;
                $failedRows = 0;

                foreach ($reader->rows($path) as $rowNumber => $raw) {
                    $normalized = $this->normalizeRow($raw, $normalizedMap);
                    $externalRef = $this->externalRef($normalized);
                    $errors = $this->validateRow($batch, $normalized, $externalRef, $requiredFields);
                    $status = $errors === [] ? 'valid' : 'invalid';

                    if ($status === 'valid') {
                        $validRows++;
                    } else {
                        $failedRows++;
                    }

                    ImportRow::query()->create([
                        'import_batch_id' => $batch->id,
                        'profile' => $batch->profile,
                        'row_number' => $rowNumber,
                        'raw' => $raw,
                        'normalized' => $normalized,
                        'status' => $status,
                        'errors' => $errors,
                        'external_ref' => $externalRef,
                    ]);
                }

                $batch->update([
                    'status' => 'previewed',
                    'valid_rows' => $validRows,
                    'failed_rows' => $failedRows,
                ]);
            });
        } catch (ApiException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $batch->update(['status' => 'failed']);
            throw $this->fileError('Berkas impor gagal divalidasi.');
        }

        return $this->show($uuid);
    }

    public function show(string $uuid): array
    {
        return $this->batchPayload($this->find($uuid));
    }

    public function rows(string $uuid, array $filters = []): LengthAwarePaginator
    {
        $batch = $this->find($uuid);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        return $batch->rows()
            ->orderBy('row_number')
            ->paginate($perPage);
    }

    public function cancel(string $uuid): void
    {
        $batch = $this->find($uuid);

        if ($batch->status === 'committing') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Batch impor sedang diproses dan belum bisa dibatalkan.', 422);
        }

        DB::connection('tenant')->transaction(function () use ($batch): void {
            Storage::disk('local')->delete($batch->stored_path);
            $batch->delete();
        });
    }

    /**
     * Profil master data (Fase 1) commit sinkron — ratusan baris tanpa
     * posting jurnal selesai dalam hitungan detik. Profil transaksi
     * (async=true) mengirim job ke antrean (Fase 2).
     *
     * Profil yang belum punya committer tetap ditolak eksplisit.
     */
    public function commit(string $uuid): array
    {
        $batch = $this->find($uuid);

        if (! $this->committers->has($batch->profile)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Commit untuk profil ini belum tersedia. Profil transaksi menunggu antrean (Fase 2 rencana impor data).',
                422
            );
        }

        if ($batch->status !== 'previewed') {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Batch impor harus melalui pemetaan kolom dan pratinjau sebelum di-commit.',
                422
            );
        }

        if ($batch->valid_rows === 0) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Tidak ada baris valid untuk di-commit.',
                422
            );
        }

        // Profil transaksi: kirim ke antrean. Worker memprosesnya di latar
        // supaya tidak menabrak max_execution_time PHP-FPM.
        if ($this->isAsyncProfile($batch->profile)) {
            $batch->update(['status' => 'committing']);

            ImportBatchJob::dispatch([
                'uuid' => $batch->uuid,
                'company_id' => $this->companyId(),
            ]);

            return $this->show($uuid);
        }

        // Profil master data: commit sinkron.
        $batch->update(['status' => 'committing']);

        $results = $this->committers->make($batch->profile)->commit($batch);

        $committedCount = 0;
        foreach ($results as $rowId => $result) {
            $committedCount += $result['status'] === 'committed' ? 1 : 0;

            ImportRow::query()->whereKey($rowId)->update([
                'status' => $result['status'],
                'document_id' => $result['document_id'],
                'document_type' => $result['document_type'],
                'errors' => $result['error'] !== null ? ['commit' => [$result['error']]] : null,
            ]);
        }

        $batch->update([
            'status' => $committedCount > 0 ? 'completed' : 'failed',
            'committed_rows' => $committedCount,
        ]);

        return $this->show($uuid);
    }

    private function isAsyncProfile(string $profile): bool
    {
        return (bool) config("imports.profiles.{$profile}.async", false);
    }

    private function inspect(SpreadsheetReader $reader, string $path): array
    {
        try {
            $headers = $reader->headers($path);
            $totalRows = 0;

            foreach ($reader->rows($path) as $_) {
                $totalRows++;

                if ($totalRows > (int) config('imports.max_rows', 1000)) {
                    break;
                }
            }

            return [$headers, $totalRows];
        } catch (Throwable $exception) {
            throw $this->fileError('Berkas impor kosong, rusak, atau tidak bisa dibaca.');
        }
    }

    private function assertHeadersAndRowCount(array $headers, int $totalRows): void
    {
        if ($headers === [] || collect($headers)->every(fn (string $header): bool => $header === '')) {
            throw $this->fileError('Berkas impor tidak memiliki header.');
        }

        if ($totalRows === 0) {
            throw $this->fileError('Berkas impor harus memiliki minimal satu baris data.');
        }

        $maxRows = (int) config('imports.max_rows', 1000);
        if ($totalRows > $maxRows) {
            throw $this->fileError("Berkas impor berisi {$totalRows} baris. Batas maksimal adalah {$maxRows} baris.");
        }
    }

    private function normalizeColumnMap(array $columnMap): array
    {
        $normalized = [];

        foreach ($columnMap as $field => $header) {
            $fieldKey = Str::of((string) $field)->trim()->lower()->snake()->toString();
            $headerName = trim((string) $header);

            if ($fieldKey !== '' && $headerName !== '') {
                $normalized[$fieldKey] = $headerName;
            }
        }

        if ($normalized === []) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Pemetaan kolom wajib diisi.',
                422,
                ['column_map' => ['Pemetaan kolom wajib diisi.']]
            );
        }

        return $normalized;
    }

    private function normalizeRow(array $raw, array $columnMap): array
    {
        $normalized = [];

        foreach ($columnMap as $field => $header) {
            $normalized[$field] = trim((string) ($raw[$header] ?? ''));
        }

        return $normalized;
    }

    private function validateRow(ImportBatch $batch, array $normalized, ?string $externalRef, array $requiredFields): array
    {
        $errors = [];

        foreach ($requiredFields as $field) {
            if (trim((string) ($normalized[$field] ?? '')) === '') {
                $errors[$field][] = Str::headline($field).' wajib diisi.';
            }
        }

        // Aturan bisnis khusus profil (kode duplikat, akun induk, kategori/
        // satuan tak dikenal, dst) — di atas required_fields generik. Profil
        // yang belum punya committer (mis. profil transaksi) dilewati di
        // sini; commit()-nya sendiri yang menolak secara eksplisit.
        if ($this->committers->has($batch->profile)) {
            $profileErrors = $this->committers->make($batch->profile)->validateRow($batch, $normalized);
            foreach ($profileErrors as $field => $messages) {
                $errors[$field] = array_merge($errors[$field] ?? [], $messages);
            }
        }

        if ($externalRef !== null) {
            $duplicate = ImportRow::query()
                ->where('profile', $batch->profile)
                ->where('external_ref', $externalRef)
                ->where('import_batch_id', '!=', $batch->id)
                ->with('batch:id,uuid,created_at')
                ->first();

            if ($duplicate instanceof ImportRow && $duplicate->batch instanceof ImportBatch) {
                $errors['ref'][] = sprintf(
                    'Ref %s sudah pernah dipakai di batch %s pada %s.',
                    $externalRef,
                    $duplicate->batch->uuid,
                    $duplicate->batch->created_at?->toDateString() ?? '-'
                );
            }
        }

        return $errors;
    }

    private function externalRef(array $normalized): ?string
    {
        $ref = trim((string) ($normalized['ref'] ?? $normalized['external_ref'] ?? ''));

        return $ref === '' ? null : $ref;
    }

    private function find(string $uuid): ImportBatch
    {
        return ImportBatch::query()->where('uuid', $uuid)->firstOrFail();
    }

    private function duplicateFile(string $profile, string $fileHash): ?array
    {
        $batch = ImportBatch::query()
            ->where('profile', $profile)
            ->where('file_hash', $fileHash)
            ->latest('id')
            ->first();

        if (! $batch instanceof ImportBatch) {
            return null;
        }

        return [
            'uuid' => $batch->uuid,
            'status' => $batch->status,
            'uploaded_at' => $batch->created_at?->toDateTimeString(),
        ];
    }

    private function ensureNoActiveBatch(): void
    {
        $active = ImportBatch::query()->active()->latest('id')->first();

        if (! $active instanceof ImportBatch) {
            return;
        }

        throw ApiException::make(
            ApiErrorCode::IMPORT_ACTIVE_BATCH_EXISTS,
            'Masih ada batch impor aktif. Selesaikan atau batalkan batch itu sebelum mengunggah berkas baru.',
            409,
            [],
            ['batch_uuid' => $active->uuid, 'status' => $active->status]
        );
    }

    private function ensureProfileExists(string $profile): void
    {
        if (! array_key_exists($profile, (array) config('imports.profiles', []))) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Profil impor tidak dikenal.',
                422,
                ['profile' => ['Profil impor tidak dikenal.']]
            );
        }
    }

    /**
     * Satu-satunya jalur yang bisa menambah penyimpanan perusahaan secara
     * melonjak (Fase 4, skema tier) — diperiksa sebelum berkas dibaca/dihash,
     * supaya penolakan tidak menunggu kerja yang percuma.
     */
    private function ensureStorageQuotaAvailable(int $incomingBytes): void
    {
        $company = $this->tenantContext->company();

        if (! $company || $this->storageQuota->canAccept($company, $incomingBytes)) {
            return;
        }

        throw ApiException::make(
            ApiErrorCode::STORAGE_QUOTA_EXCEEDED,
            'Kuota penyimpanan perusahaan ini sudah penuh.',
            422,
            ['file' => ['Kuota penyimpanan perusahaan ini sudah penuh.']],
            $this->storageQuota->summaryFor($company)
        );
    }

    private function requiredFields(string $profile): array
    {
        return (array) config("imports.profiles.{$profile}.required_fields", []);
    }

    private function companyId(): int
    {
        return (int) $this->tenantContext->companyId();
    }

    private function batchPayload(ImportBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'uuid' => $batch->uuid,
            'profile' => $batch->profile,
            'original_filename' => $batch->original_filename,
            'stored_path' => $batch->stored_path,
            'file_hash' => $batch->file_hash,
            'column_map' => $batch->column_map,
            'status' => $batch->status,
            'total_rows' => $batch->total_rows,
            'valid_rows' => $batch->valid_rows,
            'failed_rows' => $batch->failed_rows,
            'committed_rows' => $batch->committed_rows,
            'created_by' => $batch->created_by,
            'created_at' => $batch->created_at?->toISOString(),
            'updated_at' => $batch->updated_at?->toISOString(),
        ];
    }

    private function fileError(string $message): ApiException
    {
        return ApiException::make(
            ApiErrorCode::IMPORT_FILE_INVALID,
            $message,
            422,
            ['file' => [$message]]
        );
    }
}
