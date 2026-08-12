<?php

namespace Tests\Feature\Architecture;

use SplFileInfo;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Penegak batas modul setelah refactor modularisasi (Fase 8).
 *
 * Menjaga agar arsitektur tidak mundur: tidak ada namespace lama pra-refactor,
 * modul tidak saling meng-import internal (kecuali integrasi yang diizinkan),
 * dan Shared tidak bergantung ke Modules (kecuali pengecualian terdokumentasi).
 */
class ModuleBoundariesTest extends TestCase
{
    /** Prefix namespace lama yang harus sudah tidak ada lagi di app/. */
    private const LEGACY_NAMESPACES = [
        'App\\Http\\Controllers\\Api\\',
        'App\\Http\\Requests\\',
        'App\\Http\\Middleware\\',
        'App\\Services\\',
        'App\\Support\\',
        'App\\Models\\',
        'App\\Traits\\',
        'App\\Data\\',
        'App\\Contracts\\',
        'App\\Enums\\',
    ];

    /**
     * Baseline coupling lintas-modul yang SUDAH ADA sebelum/selama modularisasi.
     * Format: "{ModulSumber} → {FQCN target}". Ini didokumentasikan (bukan diizinkan
     * selamanya) — tujuan jangka panjang: kurangi via kontrak/event. Jangan tambah entri
     * baru tanpa alasan; test gagal untuk coupling di LUAR daftar ini.
     *
     * @var list<string>
     */
    private const KNOWN_MODULE_COUPLING = [
        'Accounting → App\\Modules\\Reports\\Services\\ProfitLossService',
        'Accounting → App\\Modules\\Reports\\Services\\TrialBalanceService',
        'Accounting → App\\Modules\\FixedAssets\\Services\\FixedAssetService',
        'CashBank → App\\Modules\\Reports\\Services\\LedgerBalanceCalculator',
        'Inventory → App\\Modules\\Purchase\\Services\\PurchaseAccountResolverService',
        'Inventory → App\\Modules\\Settings\\Services\\CompanySettingService',
        'Inventory → App\\Modules\\Journal\\Services\\SystemJournalBuilder',
        'Journal → App\\Modules\\Budget\\Services\\BudgetWarningService',
        'Journal → App\\Modules\\Settings\\Services\\CompanySettingService',
        'OpeningBalance → App\\Modules\\Accounting\\Services\\FiscalYearService',
        'Purchase → App\\Modules\\Settings\\Services\\CompanySettingService',
        'Purchase → App\\Modules\\Inventory\\Services\\InventoryPurchaseIntegrationService',
        'Purchase → App\\Modules\\MasterData\\Services\\AccountMappingStorageService',
        'Reports → App\\Modules\\Budget\\Requests\\BudgetComparisonRequest',
        'Reports → App\\Modules\\Budget\\Services\\BudgetComparisonService',
        'Reports → App\\Modules\\Inventory\\Services\\InventoryValuationService',
        'Reports → App\\Modules\\Purchase\\Services\\APSubsidiaryLedgerService',
        'Reports → App\\Modules\\Sales\\Services\\ARSubsidiaryLedgerService',
        'Reports → App\\Modules\\Accounting\\Services\\FiscalYearService',
        'Sales → App\\Modules\\Settings\\Services\\CompanySettingService',
        'Sales → App\\Modules\\MasterData\\Services\\AccountMappingStorageService',
        'Sales → App\\Modules\\Inventory\\Services\\InventorySalesIntegrationService',
        'Setup → App\\Modules\\OpeningBalance\\Services\\OpeningBalanceBatchService',
        'Dashboard → App\\Modules\\Reports\\Services\\CashFlowService',
        'Dashboard → App\\Modules\\Reports\\Services\\FinancialSummaryService',
        'Budget → App\\Modules\\Settings\\Services\\CompanySettingService',
        // Rencana impor data, Fase 1: aturan tunggal yang tidak boleh
        // dilanggar adalah importer WAJIB lewat service dokumen yang sudah
        // ada, bukan menulis model langsung -- lihat
        // Finlite_knowladge/plans/data-import/README.md. Coupling ini bukan
        // kebocoran lapis, ia SATU-SATUNYA cara mematuhi aturan itu.
        'Imports → App\\Modules\\MasterData\\Services\\ContactService',
        'Imports → App\\Modules\\MasterData\\Services\\ProductService',
        'Imports → App\\Modules\\MasterData\\Services\\ChartOfAccountService',
        // Committer profil transaksi (Fase 4) memanggil service modul lain untuk
        // benar-benar membuat dokumen — sama seperti coupling MasterData di atas.
        'Imports → App\\Modules\\Journal\\Services\\JournalEntryService',
        'Imports → App\\Modules\\Sales\\Services\\SalesInvoiceService',
        'Imports → App\\Modules\\Purchase\\Services\\VendorBillService',
    ];

    /**
     * Baseline dependensi Shared→Modules yang sudah ada (Shared mereferensi model/service
     * domain). Format: "{path relatif dari app/Shared/} → {FQCN}". Lihat 00-conventions §4.
     *
     * @var list<string>
     */
    private const KNOWN_SHARED_COUPLING = [
        'DocumentNumbering/DocumentNumberService.php → App\\Modules\\Accounting\\Services\\FiscalYearService',
        'SourceDocument/SourceDocumentPickerService.php → App\\Modules\\Purchase\\Models\\GoodsReceipt',
        'SourceDocument/SourceDocumentPickerService.php → App\\Modules\\Purchase\\Models\\PurchaseOrder',
        'SourceDocument/SourceDocumentPickerService.php → App\\Modules\\Purchase\\Models\\PurchaseRequest',
        'SourceDocument/SourceDocumentPickerService.php → App\\Modules\\Sales\\Models\\DeliveryOrder',
        'SourceDocument/SourceDocumentPickerService.php → App\\Modules\\Sales\\Models\\ProformaInvoice',
        'SourceDocument/SourceDocumentPickerService.php → App\\Modules\\Sales\\Models\\SalesOrder',
        'SourceDocument/SourceDocumentPickerService.php → App\\Modules\\Sales\\Models\\SalesQuotation',
        'TransactionLifecycle/PaymentTermDueDateService.php → App\\Modules\\MasterData\\Models\\Contact',
        'TransactionLifecycle/PaymentTermDueDateService.php → App\\Modules\\MasterData\\Models\\PaymentTerm',
        'TransactionLifecycle/PaymentTermDueDateService.php → App\\Modules\\Settings\\Services\\CompanySettingService',
        'TransactionLifecycle/TransactionDateGuardService.php → App\\Modules\\Accounting\\Services\\AnnualClosingGateService',
        'TransactionLifecycle/TransactionDateGuardService.php → App\\Modules\\Accounting\\Services\\FiscalYearService',
        'TransactionLifecycle/TransactionDateGuardService.php → App\\Modules\\Accounting\\Services\\PeriodLockService',
        'TransactionLifecycle/TransactionDateGuardService.php → App\\Modules\\Settings\\Services\\CompanySettingService',
        'TransactionLifecycle/TransactionPolicyService.php → App\\Modules\\Settings\\Services\\CompanySettingService',
        'TransactionLifecycle/TransactionVoidEffectService.php → App\\Modules\\Inventory\\Models\\StockMovement',
        'TransactionLifecycle/TransactionVoidEffectService.php → App\\Modules\\Inventory\\Services\\StockMovementService',
        'TransactionLifecycle/TransactionVoidEffectService.php → App\\Modules\\Journal\\Models\\JournalEntry',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\AccountMapping',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\ChartOfAccount',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\Contact',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\Department',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\PaymentTerm',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\Product',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\Project',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\Unit',
        'Validation/BusinessReferenceValidator.php → App\\Modules\\MasterData\\Models\\Warehouse',
        'Reports/HasReportVisibility.php → App\\Modules\\Reports\\Services\\ReportVisibilityService',
    ];

    public function test_no_legacy_namespaces_remain_in_app(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(app_path()) as $file) {
            $contents = $file->getContents();

            foreach (self::LEGACY_NAMESPACES as $legacy) {
                // Cocokkan token FQCN (boleh diawali backslash), bukan substring nama lain.
                $pattern = '/(?<![A-Za-z0-9_])\\\\?'.preg_quote($legacy, '/').'[A-Za-z0-9_]/';
                if (preg_match($pattern, $contents) === 1) {
                    $offenders[] = $this->relative($file).' → '.$legacy;
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Namespace lama masih ditemukan (harus dimigrasi ke App\\Shared / App\\Modules):\n".implode("\n", $offenders)
        );
    }

    public function test_modules_do_not_import_other_modules_internal_layers(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(app_path('Modules')) as $file) {
            $contents = $file->getContents();
            $selfModule = $this->moduleOf($this->classNamespace($contents));
            if ($selfModule === null) {
                continue;
            }

            foreach ($this->imports($contents) as $import) {
                if (! str_starts_with($import, 'App\\Modules\\')) {
                    continue;
                }
                $targetModule = $this->moduleOf($import);
                if ($targetModule === null || $targetModule === $selfModule) {
                    continue;
                }
                // Model & Support (value object) lintas-modul diizinkan (dijembatani relasi/DTO).
                if (in_array($this->layerOf($import), ['Models', 'Support'], true)) {
                    continue;
                }
                $pair = "{$selfModule} → {$import}";
                if (in_array($pair, self::KNOWN_MODULE_COUPLING, true)) {
                    continue;
                }
                $offenders[] = $this->relative($file).' :: '.$pair;
            }
        }

        // Test ini bersifat regresi: hanya gagal untuk coupling BARU di luar baseline
        // known-coupling (didokumentasikan). Coupling nyata yang belum dilepas dicatat
        // di KNOWN_MODULE_COUPLING, bukan dipaksa refactor di Fase 8 (lihat plan §E).
        $this->assertSame(
            [],
            $offenders,
            "Coupling lintas-modul BARU (di luar baseline known-coupling):\n".implode("\n", $offenders)
        );
    }

    public function test_shared_does_not_depend_on_modules(): void
    {
        // Pengecualian terdokumentasi (00-conventions §4 + Fase 7):
        $allowedFiles = [
            // Checkers mengimplementasi contract bersama & mereferensi model domain.
            'Shared/TransactionLifecycle/Checkers',
            // SharedServiceProvider mendaftarkan morphMap seluruh model (butuh import model modul).
            'Shared/Providers/SharedServiceProvider.php',
        ];

        $offenders = [];

        foreach ($this->phpFiles(app_path('Shared')) as $file) {
            $rel = $this->relative($file);
            foreach ($allowedFiles as $allow) {
                if (str_contains($rel, $allow)) {
                    continue 2;
                }
            }

            $selfFile = str_replace('app/Shared/', '', $rel);
            foreach ($this->imports($file->getContents()) as $import) {
                if (! str_starts_with($import, 'App\\Modules\\')) {
                    continue;
                }
                if (in_array("{$selfFile} → {$import}", self::KNOWN_SHARED_COUPLING, true)) {
                    continue;
                }
                $offenders[] = $rel.' → '.$import;
            }
        }

        // Regresi: hanya gagal untuk dependensi Shared→Modules BARU. Coupling nyata yang
        // sudah ada (mis. BusinessReferenceValidator/TransactionLifecycle mereferensi model
        // domain) dicatat di KNOWN_SHARED_COUPLING (plan §E — jangan paksa refactor besar).
        $this->assertSame(
            [],
            $offenders,
            "Dependensi Shared→Modules BARU (di luar baseline known-coupling):\n".implode("\n", $offenders)
        );
    }

    /** @return iterable<SplFileInfo> */
    private function phpFiles(string $dir): iterable
    {
        if (! is_dir($dir)) {
            return [];
        }

        return (new Finder)->files()->in($dir)->name('*.php');
    }

    private function relative(SplFileInfo $file): string
    {
        return str_replace(base_path().'/', '', $file->getPathname());
    }

    /** @return list<string> */
    private function imports(string $contents): array
    {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+\w+)?;/m', $contents, $m);

        return $m[1] ?? [];
    }

    private function classNamespace(string $contents): ?string
    {
        return preg_match('/^namespace\s+([A-Za-z0-9_\\\\]+);/m', $contents, $m) === 1 ? $m[1] : null;
    }

    private function classFqcn(string $contents): ?string
    {
        $ns = $this->classNamespace($contents);
        if ($ns === null || preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $contents, $m) !== 1) {
            return null;
        }

        return $ns.'\\'.$m[1];
    }

    /** Nama modul dari FQCN App\Modules\{X}\... */
    private function moduleOf(?string $fqcn): ?string
    {
        if ($fqcn !== null && preg_match('/^App\\\\Modules\\\\([A-Za-z0-9_]+)\\\\/', $fqcn, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    /** Layer (Services|Controllers|Requests|Models|Support) dari FQCN modul. */
    private function layerOf(string $fqcn): ?string
    {
        return preg_match('/^App\\\\Modules\\\\[A-Za-z0-9_]+\\\\([A-Za-z0-9_]+)/', $fqcn, $m) === 1 ? $m[1] : null;
    }
}
