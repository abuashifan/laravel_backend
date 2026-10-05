<?php

namespace App\Modules\MasterData\Services;

use App\Modules\Imports\Services\ImportBatchService;
use App\Modules\Imports\Services\SpreadsheetReaderFactory;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Shared\AccountMapping\AccountMappingService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;

class AccountMappingStorageService
{
    public function __construct(
        private readonly AccountMappingService $definitionService,
        private readonly SpreadsheetReaderFactory $readerFactory,
        private readonly ImportBatchService $importBatches,
    ) {}

    public function syncDefaultMappingsFromConfig(): void
    {
        $validKeys = [];

        foreach ($this->definitionService->allRequirements() as $req) {
            $validKeys[] = $req->key;
            $mapping = AccountMapping::query()->firstOrNew(['mapping_key' => $req->key]);
            $mapping->module = $req->module;
            $mapping->is_required = $req->required;
            if (! $mapping->exists) {
                $mapping->account_id = null;
                $mapping->is_active = true;
            }
            if ($mapping->account_id === null) {
                $mapping->account_id = $this->defaultAccountIdForRequirement($req);
            }
            $mapping->save();
        }

        // Remove stale keys from older schemas that are no longer defined in config
        // (e.g. cash.bank, purchase.payable, sales.receivable).
        AccountMapping::query()
            ->whereNotIn('mapping_key', $validKeys)
            ->delete();
    }

    public function list()
    {
        return AccountMapping::query()
            ->with('account')
            ->orderBy('module')
            ->orderBy('mapping_key')
            ->get()
            ->map(function (AccountMapping $mapping): array {
                $requirement = $this->definitionService->requirement($mapping->mapping_key);

                return array_merge($mapping->toArray(), [
                    'label' => $requirement?->label,
                    'description' => $requirement?->description,
                    'account_types' => $requirement?->accountTypes ?? [],
                    'visible_in_settings' => $requirement?->visibleInSettings ?? false,
                    'settings_section' => $requirement?->settingsSection,
                    'settings_order' => $requirement?->settingsOrder ?? 999,
                    'account_code' => $mapping->account?->account_code,
                    'account_name' => $mapping->account?->account_name,
                ]);
            })
            ->sortBy([
                ['visible_in_settings', 'desc'],
                ['settings_order', 'asc'],
                ['settings_section', 'asc'],
                ['mapping_key', 'asc'],
            ])
            ->values();
    }

    public function updateMapping(string $key, ?int $accountId): AccountMapping
    {
        if (! $this->definitionService->exists($key)) {
            throw ApiException::make('UNKNOWN_MAPPING_KEY', 'Unknown mapping key.', 404);
        }

        $mapping = AccountMapping::query()->where('mapping_key', $key)->first();
        if (! $mapping) {
            $req = $this->definitionService->requirement($key);
            $mapping = AccountMapping::query()->create([
                'mapping_key' => $key,
                'module' => $req?->module ?? $this->definitionService->requirement($key)?->module ?? 'unknown',
                'is_required' => (bool) ($req?->required ?? $this->definitionService->isRequired($key)),
                'is_active' => true,
            ]);
        }

        if ($accountId !== null) {
            $account = ChartOfAccount::query()->find($accountId);
            if (! $account) {
                throw ApiException::make('ACCOUNT_NOT_FOUND', 'Account not found.', 422);
            }

            if (! $account->is_active) {
                throw ApiException::make('ACCOUNT_INACTIVE', 'Account must be active.', 422);
            }

            if ($account->children()->exists()) {
                throw ApiException::make('ACCOUNT_NOT_POSTABLE', 'Akun induk tidak bisa dipakai sebagai account mapping -- pilih akun anak (leaf).', 422);
            }

            $this->validateMappingAccountType($key, $account);
        }

        $mapping->account_id = $accountId;
        $mapping->save();

        return $mapping->refresh();
    }

    public function validateMappingAccountType(string $key, ChartOfAccount $account): void
    {
        $result = $this->definitionService->validateAccountTypeForKey($key, $account->account_type);
        if (! $result['valid']) {
            throw ApiException::make('ACCOUNT_TYPE_NOT_ALLOWED', 'Account type is not allowed for this mapping key.', 422, [
                'errors' => $result['errors'],
            ]);
        }
    }

    private function defaultAccountIdForRequirement($req): ?int
    {
        foreach ($req->defaultAccountCodes as $accountCode) {
            $account = ChartOfAccount::query()
                ->where('account_code', (string) $accountCode)
                ->where('is_active', true)
                ->first();

            if (! $account || ! $req->allowsAccountType($account->account_type)) {
                continue;
            }

            // Guard yang sama dengan setMapping(): akun induk hanya untuk rekap
            // saldo dan ditolak JournalValidationService saat posting. Tanpa cek
            // ini, sync default memasang nilai yang user sendiri tidak boleh
            // pilih lewat halaman Pemetaan Akun -- dan setiap jurnal yang memakai
            // mapping itu gagal posting.
            if ($account->children()->exists()) {
                continue;
            }

            return (int) $account->id;
        }

        return null;
    }

    public function requiredMappingsComplete(?string $module = null): bool
    {
        $missing = $this->missingRequiredMappings($module);

        return $missing === [];
    }

    public function missingRequiredMappings(?string $module = null): array
    {
        $requiredKeys = $this->definitionService->requiredKeys($module);

        $existing = AccountMapping::query()
            ->whereIn('mapping_key', $requiredKeys)
            ->whereNotNull('account_id')
            ->pluck('mapping_key')
            ->all();

        return array_values(array_diff($requiredKeys, $existing));
    }

    /**
     * Baca berkas CSV/XLSX (Mapping Key + Account Code) dan terapkan tiap
     * baris lewat `updateMapping()` yang sudah ada -- tidak ada jalur
     * penyimpanan baru, cuma cara lebih cepat memakai `updateMapping()`
     * berkali-kali daripada klik satu-satu di layar.
     *
     * Baris dengan Account Code kosong SENGAJA dibiarkan (mapping yang sudah
     * ada untuk key itu tidak disentuh) -- bukan diartikan "kosongkan". Impor
     * parsial (cuma mengisi beberapa key) tidak boleh diam-diam menghapus
     * mapping lain yang sudah benar.
     *
     * @return array{results: list<array{row:int, mapping_key:string, status:string, account_code:?string, message:?string}>, applied_count:int, skipped_count:int, error_count:int}
     */
    public function importFromFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $reader = $this->readerFactory->make($extension);
        $path = $file->getRealPath();

        if ($path === false) {
            throw ApiException::make('ACCOUNT_MAPPING_IMPORT_FILE_INVALID', 'Berkas tidak bisa dibaca.', 422, [
                'file' => ['Berkas tidak bisa dibaca.'],
            ]);
        }

        $headers = $reader->headers($path);
        $guess = $this->importBatches->guessColumnMap('account_mapping', $headers);

        if ($guess['unmapped_required'] !== []) {
            throw ApiException::make(
                'ACCOUNT_MAPPING_IMPORT_HEADERS_NOT_RECOGNIZED',
                'Kolom wajib tidak ditemukan di berkas: '.implode(', ', $guess['unmapped_required']).
                '. Pastikan berkas punya kolom Mapping Key dan Account Code -- unduh templatnya lewat tombol Unduh Templat.',
                422,
            );
        }

        $columnMap = $guess['map'];
        $results = [];
        $appliedCount = 0;
        $skippedCount = 0;
        $errorCount = 0;

        foreach ($reader->rows($path) as $rowNumber => $raw) {
            $mappingKey = trim((string) ($raw[$columnMap['mapping_key'] ?? ''] ?? ''));
            $accountCode = trim((string) ($raw[$columnMap['account_code'] ?? ''] ?? ''));

            if ($mappingKey === '') {
                $results[] = ['row' => $rowNumber, 'mapping_key' => '', 'status' => 'error', 'account_code' => null, 'message' => 'Mapping Key wajib diisi.'];
                $errorCount++;

                continue;
            }

            if (! $this->definitionService->exists($mappingKey)) {
                $results[] = ['row' => $rowNumber, 'mapping_key' => $mappingKey, 'status' => 'error', 'account_code' => null, 'message' => "Mapping key \"{$mappingKey}\" tidak dikenal."];
                $errorCount++;

                continue;
            }

            if ($accountCode === '') {
                $results[] = ['row' => $rowNumber, 'mapping_key' => $mappingKey, 'status' => 'skipped', 'account_code' => null, 'message' => null];
                $skippedCount++;

                continue;
            }

            $account = ChartOfAccount::query()->where('account_code', $accountCode)->first();

            if (! $account) {
                $results[] = ['row' => $rowNumber, 'mapping_key' => $mappingKey, 'status' => 'error', 'account_code' => $accountCode, 'message' => "Kode akun \"{$accountCode}\" tidak ditemukan."];
                $errorCount++;

                continue;
            }

            try {
                $this->updateMapping($mappingKey, $account->id);
                $results[] = ['row' => $rowNumber, 'mapping_key' => $mappingKey, 'status' => 'applied', 'account_code' => $accountCode, 'message' => null];
                $appliedCount++;
            } catch (ApiException $exception) {
                $results[] = ['row' => $rowNumber, 'mapping_key' => $mappingKey, 'status' => 'error', 'account_code' => $accountCode, 'message' => $exception->getMessage()];
                $errorCount++;
            }
        }

        return [
            'results' => $results,
            'applied_count' => $appliedCount,
            'skipped_count' => $skippedCount,
            'error_count' => $errorCount,
        ];
    }

    /**
     * Templat unduhan: satu baris per mapping key TERDAFTAR SAAT INI (bukan
     * contoh statis) -- user cukup isi/ubah kolom Account Code, bukan
     * menghafal kunci yang valid. Kolom Account Code diisi awal dengan
     * mapping yang sudah ada, supaya mengunggah ulang file ini apa adanya
     * tanpa diubah bersifat no-op (sejalan dengan "kosong = jangan disentuh"
     * di `importFromFile()`).
     *
     * @return array{filename:string, headers:list<string>, fields:list<string>, rows:list<list<string>>, reference:list<array{field:?string, title:string, headers:list<string>, rows:list<list<string>>}>}
     */
    public function importTemplate(): array
    {
        $this->syncDefaultMappingsFromConfig();

        $currentByKey = AccountMapping::query()->with('account')->get()->keyBy('mapping_key');

        $rows = [];
        foreach ($this->definitionService->allRequirements() as $req) {
            $current = $currentByKey->get($req->key);
            $rows[] = [
                $req->key,
                $req->label,
                $req->module,
                $req->required ? 'Wajib' : 'Opsional',
                implode(', ', $req->accountTypes),
                $current?->account?->account_code ?? '',
            ];
        }

        return [
            'filename' => 'template-account-mapping',
            'headers' => ['Mapping Key', 'Label', 'Modul', 'Wajib', 'Tipe Akun Diizinkan', 'Account Code'],
            'fields' => ['mapping_key', 'label', 'module', 'required', 'account_types', 'account_code'],
            'rows' => $rows,
            'reference' => [[
                'field' => 'account_code',
                'title' => 'Chart of Account (akun anak/leaf saja)',
                'headers' => ['Kode', 'Nama'],
                'rows' => ChartOfAccount::query()
                    ->where('is_active', true)
                    ->whereDoesntHave('children')
                    ->orderBy('account_code')
                    ->get(['account_code', 'account_name'])
                    ->map(fn (ChartOfAccount $account): array => [(string) $account->account_code, (string) $account->account_name])
                    ->all(),
            ]],
        ];
    }
}
