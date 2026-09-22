<?php

namespace App\Modules\Setup\Services;

use App\Modules\FixedAssets\Services\FixedAssetCategoryAccountLinker;
use App\Modules\Journal\Models\JournalEntryLine;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Services\AccountMappingStorageService;
use App\Modules\MasterData\Services\ChartOfAccountService;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use App\Shared\Tenant\TenantStarterDataService;
use Illuminate\Support\Facades\DB;

class CoaTemplateService
{
    public function __construct(
        private readonly ChartOfAccountService $chartOfAccountService,
        private readonly AccountMappingStorageService $accountMappingStorageService,
        private readonly FixedAssetCategoryAccountLinker $fixedAssetCategoryAccountLinker,
        private readonly CompanySettingService $companySettingService,
        private readonly TenantContext $tenantContext,
        private readonly TenantStarterDataService $starterData,
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
}
