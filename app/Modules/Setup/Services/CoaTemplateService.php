<?php

namespace App\Modules\Setup\Services;

use App\Modules\FixedAssets\Services\FixedAssetCategoryAccountLinker;
use App\Modules\Imports\Services\ImportBatchService;
use App\Modules\Imports\Services\SpreadsheetReaderFactory;
use App\Modules\Journal\Models\JournalEntryLine;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Services\AccountMappingStorageService;
use App\Modules\MasterData\Services\ChartOfAccountService;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use App\Shared\Tenant\TenantStarterDataService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class CoaTemplateService
{
    private const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** Nilai yang dianggap "ya" di kolom Kas/Bank -- sejajar dengan `ChartOfAccountImportCommitter::toBool()`. */
    private const TRUTHY = ['1', 'ya', 'yes', 'true', 'y'];

    public function __construct(
        private readonly ChartOfAccountService $chartOfAccountService,
        private readonly AccountMappingStorageService $accountMappingStorageService,
        private readonly FixedAssetCategoryAccountLinker $fixedAssetCategoryAccountLinker,
        private readonly CompanySettingService $companySettingService,
        private readonly TenantContext $tenantContext,
        private readonly TenantStarterDataService $starterData,
        private readonly SpreadsheetReaderFactory $readerFactory,
        private readonly ImportBatchService $importBatches,
    ) {}

    /**
     * @return array<int, array{id:string, label:string, description:string, account_count:int, accounts:array, modules:array<string, bool>}>
     */
    public function templates(): array
    {
        $templates = (array) config('coa_templates.templates', []);

        $result = [];
        foreach ($templates as $id => $template) {
            $accounts = (array) ($template['accounts'] ?? []);
            $result[] = [
                'id' => (string) $id,
                'label' => (string) ($template['label'] ?? $id),
                'description' => (string) ($template['description'] ?? ''),
                'account_count' => count($accounts),
                'accounts' => $accounts,
                'modules' => (array) ($template['modules'] ?? []),
            ];
        }

        return $result;
    }

    /**
     * Menerapkan template COA (atau versi yang sudah dikustomisasi user) ke
     * Chart of Accounts perusahaan. Idempotent terhadap akun bertanda
     * `is_system_default` dari pemakaian sebelumnya -- akun itu diganti,
     * akun yang dibuat manual di Master Data tidak disentuh.
     *
     * @param  array<int, array{code:string, name:string, type:string, parent_code:?string, is_cash_bank?:bool, description?:?string}>  $accounts
     * @return array<int, ChartOfAccount>
     */
    public function applyTemplate(string $templateId, array $accounts): array
    {
        if (! array_key_exists($templateId, (array) config('coa_templates.templates', []))) {
            throw ApiException::make('UNKNOWN_COA_TEMPLATE', 'Unknown COA template.', 422, [
                'template_id' => ['Unknown COA template.'],
            ]);
        }

        $previousTemplateId = $this->appliedTemplateId();

        $created = DB::connection('tenant')->transaction(function () use ($templateId, $accounts) {
            $this->replacePreviousTemplateAccounts();

            $codeToId = [];
            $created = [];

            foreach ($accounts as $row) {
                $code = (string) $row['code'];
                $parentCode = $row['parent_code'] ?? null;
                $parentId = null;

                if ($parentCode !== null) {
                    $parentCode = (string) $parentCode;
                    if (! array_key_exists($parentCode, $codeToId)) {
                        throw ApiException::make(
                            'COA_TEMPLATE_PARENT_NOT_FOUND',
                            "Parent account [{$parentCode}] must appear before its child [{$code}] in the template.",
                            422,
                        );
                    }
                    $parentId = $codeToId[$parentCode];
                }

                $account = $this->chartOfAccountService->create([
                    'account_code' => $code,
                    'account_name' => (string) $row['name'],
                    'account_type' => (string) $row['type'],
                    'parent_account_id' => $parentId,
                    'is_cash_bank' => (bool) ($row['is_cash_bank'] ?? false),
                    'description' => $row['description'] ?? null,
                    'is_system_default' => true,
                    'metadata' => ['template_id' => $templateId],
                ]);

                $codeToId[$code] = $account->id;
                $created[] = $account;
            }

            $this->accountMappingStorageService->syncDefaultMappingsFromConfig();

            // Wajib SETELAH sync mapping: kategori menyimpan chart_of_accounts.id
            // yang di-resolve lewat mapping key, jadi mapping harus sudah menunjuk
            // ke akun template yang baru dibuat di atas. Kategorinya sendiri sudah
            // ada sejak migration tenant -- yang diisi di sini hanya kolom akunnya.
            $this->fixedAssetCategoryAccountLinker->linkDefaults();

            return $created;
        });

        // Setelah transaksi tenant commit: pengaturan modul tinggal di database
        // central, jadi tidak bisa ikut transaksi di atas. Kalau ini gagal, COA
        // tetap terpasang dan modul hanya belum mengikuti -- user masih bisa
        // mengaturnya sendiri di langkah berikutnya.
        if ($previousTemplateId !== $templateId) {
            $this->applyModulePreset($templateId);
        }

        // Langkah Master Data datang sesudah ini; perusahaan lama yang belum
        // pernah mendapat Gudang Utama/PCS saat dibuat ikut terisi di sini.
        $this->starterData->seedCurrent();

        return $created;
    }

    /**
     * Template mewakili jenis usaha, jadi modulnya ikut disesuaikan (lihat
     * `modules` di config/coa_templates.php). Hanya saat template BERGANTI:
     * menerapkan ulang template yang sama -- mis. user kembali ke langkah COA
     * lalu menekan Lanjutkan lagi -- tidak boleh menimpa modul yang sudah ia
     * atur sendiri. Lewat CompanySettingService supaya aturan konsistensi
     * modul/akuntansinya tetap berlaku.
     */
    private function applyModulePreset(string $templateId): void
    {
        $preset = (array) config("coa_templates.templates.{$templateId}.modules", []);
        $company = $this->tenantContext->company();

        if ($preset === [] || ! $company) {
            return;
        }

        $this->companySettingService->updateModuleSetting($company, $preset);
    }

    /** Template yang terakhir diterapkan, dibaca dari penanda akun hasil template. */
    private function appliedTemplateId(): ?string
    {
        $account = ChartOfAccount::query()
            ->where('is_system_default', true)
            ->orderBy('id')
            ->first();

        $templateId = $account?->metadata['template_id'] ?? null;

        return is_string($templateId) ? $templateId : null;
    }

    /**
     * Menghapus akun bertanda `is_system_default` dari penerapan template
     * sebelumnya, supaya kode akun bisa dipakai ulang oleh template baru.
     * Ditolak kalau salah satu akun itu sudah dipakai jurnal/saldo awal --
     * constraint FK `restrictOnDelete()` sebenarnya sudah mencegah ini di
     * level DB, tapi guard ini memberi pesan yang jelas alih-alih exception
     * SQL mentah.
     */
    private function replacePreviousTemplateAccounts(): void
    {
        $existingIds = ChartOfAccount::query()
            ->where('is_system_default', true)
            ->pluck('id');

        if ($existingIds->isEmpty()) {
            return;
        }

        // Baris saldo awal ikut terperiksa lewat `journal_entry_lines`: sejak
        // Fase 8 saldo awal adalah jurnal biasa, bukan tabel tersendiri.
        $referenced = JournalEntryLine::query()->whereIn('account_id', $existingIds)->exists();

        if ($referenced) {
            throw ApiException::make(
                'COA_TEMPLATE_ACCOUNTS_IN_USE',
                'Template tidak bisa diganti karena akun dari template sebelumnya sudah dipakai transaksi. Kelola Chart of Accounts secara manual di Master Data.',
                422,
            );
        }

        ChartOfAccount::query()->whereIn('id', $existingIds)->delete();
    }

    /**
     * Baca berkas CSV/XLSX milik user jadi daftar akun berbentuk sama dengan
     * `accounts` di `applyTemplate()` -- hasilnya TIDAK langsung disimpan, cuma
     * dikembalikan untuk dipratinjau/diedit user lalu dikirim lewat endpoint
     * `apply` yang sudah ada. Kolom berkas ditebak lewat
     * `ImportBatchService::guessColumnMap()` milik profil `chart_of_account`
     * (config/imports.php) supaya alias header Indonesia ("Kode", "Induk", dst)
     * tetap dikenali tanpa menduplikasi logikanya di sini.
     *
     * @return array{accounts: array<int, array{code:string, name:string, type:string, parent_code:?string, is_cash_bank:bool}>, skipped: list<array{row:?int, code:?string, errors:list<string>}>}
     */
    public function importFromFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $reader = $this->readerFactory->make($extension);
        $path = $file->getRealPath();

        if ($path === false) {
            throw ApiException::make('COA_TEMPLATE_IMPORT_FILE_INVALID', 'Berkas tidak bisa dibaca.', 422, [
                'file' => ['Berkas tidak bisa dibaca.'],
            ]);
        }

        $headers = $reader->headers($path);
        $guess = $this->importBatches->guessColumnMap('chart_of_account', $headers);

        if ($guess['unmapped_required'] !== []) {
            throw ApiException::make(
                'COA_TEMPLATE_IMPORT_HEADERS_NOT_RECOGNIZED',
                'Kolom wajib tidak ditemukan di berkas: '.implode(', ', $guess['unmapped_required']).
                '. Pastikan berkas punya kolom Code/Kode, Name/Nama, dan Type/Tipe.',
                422,
            );
        }

        $columnMap = $guess['map'];
        $accounts = [];
        $skipped = [];
        $seenCodes = [];

        foreach ($reader->rows($path) as $rowNumber => $raw) {
            $code = trim((string) ($raw[$columnMap['code'] ?? ''] ?? ''));
            $name = trim((string) ($raw[$columnMap['name'] ?? ''] ?? ''));
            $type = mb_strtolower(trim((string) ($raw[$columnMap['type'] ?? ''] ?? '')));
            $parentCode = trim((string) ($raw[$columnMap['parent_code'] ?? ''] ?? ''));
            $cashBank = mb_strtolower(trim((string) ($raw[$columnMap['cash_bank'] ?? ''] ?? '')));

            $errors = [];
            if ($code === '') {
                $errors[] = 'Kode akun wajib diisi.';
            } elseif (isset($seenCodes[$code])) {
                $errors[] = "Kode akun \"{$code}\" duplikat di berkas ini.";
            }
            if ($name === '') {
                $errors[] = 'Nama akun wajib diisi.';
            }
            if ($type === '') {
                $errors[] = 'Tipe akun wajib diisi.';
            } elseif (! in_array($type, self::TYPES, true)) {
                $errors[] = "Tipe \"{$type}\" tidak dikenal. Pakai salah satu: ".implode(', ', self::TYPES).'.';
            }

            if ($errors !== []) {
                $skipped[] = ['row' => $rowNumber, 'code' => $code !== '' ? $code : null, 'errors' => $errors];

                continue;
            }

            $seenCodes[$code] = true;
            $accounts[$code] = [
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'parent_code' => $parentCode !== '' ? $parentCode : null,
                'is_cash_bank' => in_array($cashBank, self::TRUTHY, true),
            ];
        }

        return ['accounts' => $this->sortParentsFirst($accounts, $skipped), 'skipped' => $skipped];
    }

    /**
     * `applyTemplate()` mensyaratkan induk muncul SEBELUM anaknya di array --
     * urutan baris di berkas tidak boleh dipercaya (lihat catatan yang sama di
     * `ChartOfAccountImportCommitter`). Diselesaikan bertahap seperti
     * commit()-nya importer generik: proses baris yang induknya sudah pasti ada
     * dalam berkas ini, ulangi sampai tidak ada lagi kemajuan.
     *
     * @param  array<string, array{code:string, name:string, type:string, parent_code:?string, is_cash_bank:bool}>  $accountsByCode
     * @param  list<array{row:?int, code:?string, errors:list<string>}>  $skipped
     * @return array<int, array{code:string, name:string, type:string, parent_code:?string, is_cash_bank:bool}>
     */
    private function sortParentsFirst(array $accountsByCode, array &$skipped): array
    {
        $sorted = [];
        $resolved = [];
        $remaining = $accountsByCode;

        do {
            $progressed = false;

            foreach ($remaining as $code => $account) {
                $parentCode = $account['parent_code'];
                if ($parentCode === null || isset($resolved[$parentCode])) {
                    $sorted[] = $account;
                    $resolved[$code] = true;
                    unset($remaining[$code]);
                    $progressed = true;
                }
            }
        } while ($progressed && $remaining !== []);

        foreach ($remaining as $account) {
            // Kunci array `$code` bisa ditafsir ulang jadi int oleh PHP (kode akun
            // numerik seperti "1130" menjadi key int 1130) -- pakai field
            // `code` di dalam baris, yang tetap string apa adanya.
            $skipped[] = [
                'row' => null,
                'code' => $account['code'],
                'errors' => ["Kode induk \"{$account['parent_code']}\" untuk akun \"{$account['code']}\" tidak ditemukan di berkas ini."],
            ];
        }

        return $sorted;
    }
}
