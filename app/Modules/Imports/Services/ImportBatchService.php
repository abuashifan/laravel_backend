<?php

namespace App\Modules\Imports\Services;

use App\Jobs\ImportBatchJob;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Services\Committers\ImportCommitterFactory;
use App\Modules\Imports\Services\Committers\ProvidesImportWarnings;
use App\Modules\Imports\Services\Committers\RevertsImport;
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

        $guess = $this->guessColumnMap($profile, $headers);

        return [
            'batch' => $this->batchPayload($batch),
            'headers' => $headers,
            'duplicate_file' => $duplicate,
            'suggested_column_map' => $guess['map'],
            'unmapped_required_fields' => $guess['unmapped_required'],
            // Frontend melewati layar pemetaan sepenuhnya kalau ini true.
            'auto_mapped' => $guess['unmapped_required'] === [],
        ];
    }

    /**
     * Tebak pemetaan kolom dari header berkas — Fase 8.
     *
     * Layar "Petakan Kolom" ada untuk berkas yang headernya tidak dikenal.
     * Masalahnya, sampai sekarang ia MUNCUL SELALU, termasuk untuk berkas yang
     * headernya sudah persis templat — user diminta memilih ulang empat kolom
     * yang jawabannya sudah pasti. Penebakan dipindahkan ke sini supaya
     * jawabannya dihitung sekali, di tempat yang memang tahu bentuk profilnya.
     *
     * Tiga lapis pencocokan, berhenti di yang pertama kena:
     *   1. header templat milik field ini  → berkas templat apa adanya
     *   2. nama field itu sendiri          → ekspor sistem lain
     *   3. alias di `config/imports.php` → `column_aliases` → berkas berbahasa Indonesia
     *
     * Urutan kolom di berkas tidak berpengaruh: pencocokan lewat peta header,
     * bukan lewat posisi. Yang TIDAK boleh dilakukan adalah mencoba seluruh
     * header templat untuk setiap field — itu membuat `account_code` mengklaim
     * kolom "Debit" hanya karena "Debit" ada di templat yang sama.
     *
     * Satu header hanya boleh dipakai satu field: begitu terpakai ia dikeluarkan
     * dari kandidat, jadi dua field tidak pernah menunjuk kolom yang sama.
     *
     * @param  list<string>  $headers
     * @return array{map: array<string, string>, unmapped_required: list<string>}
     */
    public function guessColumnMap(string $profile, array $headers): array
    {
        $fields = (array) config("imports.profiles.{$profile}.fields", []);
        $templateHeaders = array_values((array) config("imports.profiles.{$profile}.headers", []));
        $aliases = (array) config('imports.column_aliases', []);

        // Header berkas, dikunci bentuk normalnya → nama aslinya. Bentuk normal
        // membuang huruf besar, spasi, dan tanda baca, sehingga "Kode Akun",
        // "kode_akun", dan "KODE  AKUN" jatuh ke kunci yang sama.
        $available = [];
        foreach ($headers as $header) {
            $key = $this->normalizeHeaderKey((string) $header);
            if ($key !== '' && ! array_key_exists($key, $available)) {
                $available[$key] = (string) $header;
            }
        }

        $map = [];

        foreach (array_values($fields) as $index => $field) {
            $candidates = [];

            if (isset($templateHeaders[$index])) {
                $candidates[] = $templateHeaders[$index];
            }
            $candidates[] = (string) $field;
            foreach ((array) ($aliases[$field] ?? []) as $alias) {
                $candidates[] = (string) $alias;
            }

            foreach ($candidates as $candidate) {
                $key = $this->normalizeHeaderKey((string) $candidate);

                if ($key !== '' && isset($available[$key])) {
                    $map[$field] = $available[$key];
                    unset($available[$key]);
                    break;
                }
            }
        }

        $unmapped = array_values(array_filter(
            $this->requiredFields($profile),
            fn (string $field): bool => ! isset($map[$field]),
        ));

        return ['map' => $map, 'unmapped_required' => $unmapped];
    }

    /**
     * Bentuk normal sebuah header: huruf kecil, tanpa apa pun selain huruf dan
     * angka. Disengaja seagresif ini — perbedaan yang dibuangnya ("Kode Akun"
     * vs "kode_akun") tidak pernah berarti kolom yang berbeda.
     */
    private function normalizeHeaderKey(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($value)));
    }

    public function applyMapping(string $uuid, array $columnMap): array
    {
        $batch = $this->find($uuid);

        if (in_array($batch->status, ['committing', 'completed'], true)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                "Batch impor sudah berstatus '{$batch->status}' dan tidak bisa dipetakan ulang.",
                422
            );
        }

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
                    'warning_rows' => 0,
                    'error_message' => null,
                ]);
                $batch->rows()->delete();

                $validRows = 0;
                $failedRows = 0;
                $warningRows = 0;

                foreach ($reader->rows($path) as $rowNumber => $raw) {
                    $normalized = $this->normalizeRow($raw, $normalizedMap);
                    $externalRef = $this->externalRef($normalized);
                    $errors = $this->validateRow($batch, $normalized, $externalRef, $requiredFields);
                    $status = $errors === [] ? 'valid' : 'invalid';
                    // Peringatan hanya dihitung untuk baris yang lolos: pada
                    // baris yang sudah gagal ia cuma menambah kebisingan di
                    // sebelah galat yang sudah menjelaskan masalahnya.
                    $warnings = $status === 'valid' ? $this->warnRow($batch, $normalized) : [];

                    if ($status === 'valid') {
                        $validRows++;
                    } else {
                        $failedRows++;
                    }

                    if ($warnings !== []) {
                        $warningRows++;
                    }

                    ImportRow::query()->create([
                        'import_batch_id' => $batch->id,
                        'profile' => $batch->profile,
                        'row_number' => $rowNumber,
                        'raw' => $raw,
                        'normalized' => $normalized,
                        'status' => $status,
                        'errors' => $errors,
                        'warnings' => $warnings,
                        'external_ref' => $externalRef,
                    ]);
                }

                $batch->update([
                    'status' => 'previewed',
                    'valid_rows' => $validRows,
                    'failed_rows' => $failedRows,
                    'warning_rows' => $warningRows,
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

    /**
     * Satu batch, LENGKAP dengan header berkasnya — Fase 8.
     *
     * `headers` tidak disimpan di tabel: ia hidup di berkas. Sampai sekarang ia
     * hanya dikembalikan oleh `upload()`, jadi begitu halaman di-reload batch
     * yang belum di-commit tidak punya jalan pulang — layar pemetaan butuh
     * daftar header untuk bisa digambar sama sekali. Dibaca ulang dari berkas
     * di sini, satu kali per buka batch, bukan per baris riwayat.
     */
    public function show(string $uuid): array
    {
        $batch = $this->find($uuid);
        $headers = $this->storedHeaders($batch);
        $guess = $this->guessColumnMap($batch->profile, $headers);

        return $this->batchPayload($batch) + [
            'headers' => $headers,
            'suggested_column_map' => $guess['map'],
        ];
    }

    /**
     * Header dari berkas tersimpan. Mengembalikan array kosong, bukan melempar,
     * kalau berkasnya sudah tidak ada atau rusak: batch yang sudah di-commit
     * tetap harus bisa dibuka riwayatnya meski berkasnya hilang.
     *
     * @return list<string>
     */
    private function storedHeaders(ImportBatch $batch): array
    {
        if (! Storage::disk('local')->exists($batch->stored_path)) {
            return [];
        }

        try {
            $extension = strtolower(pathinfo($batch->stored_path, PATHINFO_EXTENSION));

            return $this->readerFactory->make($extension)
                ->headers(Storage::disk('local')->path($batch->stored_path));
        } catch (Throwable) {
            return [];
        }
    }

    public function rows(string $uuid, array $filters = []): LengthAwarePaginator
    {
        $batch = $this->find($uuid);
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        return $batch->rows()
            ->orderBy('row_number')
            ->paginate($perPage);
    }

    /**
     * Riwayat impor. Tanpa ini batch lama tidak bisa dibuka lagi begitu halaman
     * ditinggalkan — UUID-nya cuma hidup di state frontend — apalagi dibatalkan.
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(max((int) ($filters['per_page'] ?? 25), 1), 100);

        $query = ImportBatch::query()->orderByDesc('id');

        if (! empty($filters['profile'])) {
            $query->where('profile', (string) $filters['profile']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        $paginator = $query->paginate($perPage);
        $paginator->getCollection()->transform(fn (ImportBatch $batch): array => $this->batchPayload($batch));

        return $paginator;
    }

    /**
     * Batalkan batch yang BELUM di-commit: berkasnya dibuang, jejaknya ikut.
     *
     * Sengaja tidak lagi menerima batch `completed`. Sampai Fase 7 ia menerima
     * status apa pun kecuali `committing`, yang berarti "batalkan" pada batch
     * yang sudah selesai justru menghapus jejak impornya **tanpa menghapus
     * datanya** — jalur yang menyesatkan sejak awal. Yang membatalkan hasil
     * commit sekarang adalah `revert()`.
     */
    public function cancel(string $uuid): void
    {
        $batch = $this->find($uuid);

        if ($batch->status === 'committing') {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Batch impor sedang diproses dan belum bisa dibatalkan.', 422);
        }

        if (in_array((string) $batch->status, ['completed', 'reverted'], true)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Batch ini sudah di-commit. Pakai Batalkan Impor (revert) untuk menarik kembali datanya.',
                422
            );
        }

        DB::connection('tenant')->transaction(function () use ($batch): void {
            Storage::disk('local')->delete($batch->stored_path);
            $batch->delete();
        });
    }

    /**
     * Kebalikan `commit()` — Fase 8.
     *
     * Batchnya TIDAK dihapus: statusnya jadi `reverted` dan barisnya ikut,
     * sehingga riwayatnya tetap menceritakan apa yang pernah masuk dan ditarik
     * lagi. Jejak itu justru yang paling dibutuhkan saat ada yang salah.
     */
    public function revert(string $uuid, string $reason): array
    {
        $batch = $this->find($uuid);

        if (! in_array((string) $batch->status, ['completed', 'failed'], true)) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Hanya batch yang sudah selesai di-commit yang bisa dibatalkan.',
                422
            );
        }

        if (! $this->committers->has($batch->profile)) {
            throw ApiException::make(ApiErrorCode::VALIDATION_ERROR, 'Profil impor ini tidak dikenal.', 422);
        }

        $committer = $this->committers->make($batch->profile);

        if (! $committer instanceof RevertsImport) {
            throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Impor profil ini tidak bisa dibatalkan otomatis. Data yang sudah masuk mungkin sudah dipakai dokumen lain — hapus atau perbaiki lewat menu modulnya.',
                422
            );
        }

        DB::connection('tenant')->transaction(function () use ($batch, $committer, $reason): void {
            $committer->revert($batch, $reason);

            $batch->rows()->where('status', 'committed')->update([
                'status' => 'reverted',
                'document_id' => null,
                'document_type' => null,
            ]);

            $batch->update([
                'status' => 'reverted',
                'committed_rows' => 0,
                'error_message' => 'Dibatalkan: '.$reason,
            ]);
        });

        return $this->show($uuid);
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

        $errorMessage = null;
        if ($committedCount === 0) {
            $errorMessage = $this->summarizeCommitErrors($results);
        }

        $batch->update([
            'status' => $committedCount > 0 ? 'completed' : 'failed',
            'committed_rows' => $committedCount,
            'error_message' => $errorMessage,
        ]);

        return $this->show($uuid);
    }

    /**
     * Rangkum pesan error dari hasil commit yang gagal.
     *
     * @param  array<int, array{status: string, error: ?string}>  $results
     */
    private function summarizeCommitErrors(array $results): string
    {
        $errors = [];

        foreach ($results as $rowId => $result) {
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

    /**
     * Peringatan tingkat baris — hal yang MUNGKIN salah tapi tetap boleh
     * di-commit. Tidak pernah mengubah status baris.
     *
     * Profil yang tidak mengimplementasikan `ProvidesImportWarnings` tidak
     * punya peringatan sama sekali, dan itu wajar: kebanyakan profil master
     * data hanya mengenal benar/salah.
     *
     * @param  array<string, string>  $normalized
     * @return array<string, list<string>>
     */
    private function warnRow(ImportBatch $batch, array $normalized): array
    {
        if (! $this->committers->has($batch->profile)) {
            return [];
        }

        $committer = $this->committers->make($batch->profile);

        if (! $committer instanceof ProvidesImportWarnings) {
            return [];
        }

        return $committer->warnRow($batch, $normalized);
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

    /**
     * Batch `reverted` sengaja dilewati: berkas yang sama diunggah ulang setelah
     * pembatalan adalah jalur perbaikan yang normal — memperingatkannya sebagai
     * duplikat berarti menghadang user tepat saat ia sedang membetulkan
     * kesalahan.
     */
    private function duplicateFile(string $profile, string $fileHash): ?array
    {
        $batch = ImportBatch::query()
            ->where('profile', $profile)
            ->where('file_hash', $fileHash)
            ->where('status', '!=', 'reverted')
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

        // Batch stuck di committing melewati timeout → anggap gagal.
        if ($active->status === 'committing' && $this->isStuckCommitting($active)) {
            $active->update([
                'status' => 'failed',
                'error_message' => 'Commit dibatalkan otomatis — melebihi batas waktu '
                    .(int) config('imports.committing_timeout_minutes', 30).' menit. '
                    .'Pastikan queue worker berjalan atau coba ulangi commit.',
            ]);

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

    private function isStuckCommitting(ImportBatch $batch): bool
    {
        $timeoutMinutes = (int) config('imports.committing_timeout_minutes', 30);

        return $batch->updated_at instanceof \DateTimeInterface
            && $batch->updated_at->diffInMinutes(now()) >= $timeoutMinutes;
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
            'warning_rows' => $batch->warning_rows,
            'committed_rows' => $batch->committed_rows,
            'error_message' => $batch->error_message,
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
