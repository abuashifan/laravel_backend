<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AliasServiceProvider extends ServiceProvider
{
    /**
     * Alias nama-lama => implementasi-baru. Bertambah tiap fase, DIHAPUS di Fase 8.
     *
     * Kunci = FQCN lama (string), nilai = class baru (::class).
     * Trait TIDAK masuk sini (di-update langsung di fase yang sama).
     */
    public const ALIASES = [
        // Api
        'App\\Support\\Api\\ApiResponseBuilder' => \App\Shared\Api\ApiResponseBuilder::class,
        'App\\Support\\Api\\ApiErrorCode' => \App\Shared\Api\ApiErrorCode::class,

        // Audit
        'App\\Services\\Audit\\AuditLogService' => \App\Shared\Audit\AuditLogService::class,
        'App\\Support\\Audit\\AuditAction' => \App\Shared\Audit\AuditAction::class,
        'App\\Support\\Audit\\AuditEvent' => \App\Shared\Audit\AuditEvent::class,
        'App\\Support\\Audit\\AuditResult' => \App\Shared\Audit\AuditResult::class,

        // DocumentNumbering
        'App\\Services\\DocumentNumbering\\DocumentNumberService' => \App\Shared\DocumentNumbering\DocumentNumberService::class,
        'App\\Support\\DocumentNumbering\\DocumentNumberFormat' => \App\Shared\DocumentNumbering\DocumentNumberFormat::class,
        'App\\Support\\DocumentNumbering\\DocumentType' => \App\Shared\DocumentNumbering\DocumentType::class,

        // TransactionLifecycle — services
        'App\\Services\\Transactions\\NoopTransactionDateGuard' => \App\Shared\TransactionLifecycle\NoopTransactionDateGuard::class,
        'App\\Services\\Transactions\\NoopTransactionDependencyChecker' => \App\Shared\TransactionLifecycle\NoopTransactionDependencyChecker::class,
        'App\\Services\\Transactions\\PaymentTermDueDateService' => \App\Shared\TransactionLifecycle\PaymentTermDueDateService::class,
        'App\\Services\\Transactions\\TransactionDateGuardService' => \App\Shared\TransactionLifecycle\TransactionDateGuardService::class,
        'App\\Services\\Transactions\\TransactionDependencyService' => \App\Shared\TransactionLifecycle\TransactionDependencyService::class,
        'App\\Services\\Transactions\\TransactionPolicyService' => \App\Shared\TransactionLifecycle\TransactionPolicyService::class,
        'App\\Services\\Transactions\\TransactionRevisionService' => \App\Shared\TransactionLifecycle\TransactionRevisionService::class,
        'App\\Services\\Transactions\\TransactionVoidEffectService' => \App\Shared\TransactionLifecycle\TransactionVoidEffectService::class,

        // TransactionLifecycle — support/revision value objects
        'App\\Support\\Transaction\\DependencyCheckResult' => \App\Shared\TransactionLifecycle\DependencyCheckResult::class,
        'App\\Support\\Transaction\\TransactionAction' => \App\Shared\TransactionLifecycle\TransactionAction::class,
        'App\\Support\\Transaction\\TransactionLifecycle' => \App\Shared\TransactionLifecycle\TransactionLifecycle::class,
        'App\\Support\\Transaction\\TransactionModule' => \App\Shared\TransactionLifecycle\TransactionModule::class,
        'App\\Support\\Transaction\\TransactionPolicyResult' => \App\Shared\TransactionLifecycle\TransactionPolicyResult::class,
        'App\\Support\\Transaction\\TransactionStatus' => \App\Shared\TransactionLifecycle\TransactionStatus::class,
        'App\\Support\\Revision\\RevisionSnapshot' => \App\Shared\TransactionLifecycle\RevisionSnapshot::class,
        'App\\Support\\Revision\\TransactionRevisionAction' => \App\Shared\TransactionLifecycle\TransactionRevisionAction::class,

        // TransactionLifecycle — contracts
        'App\\Contracts\\Transactions\\TransactionDateGuard' => \App\Shared\TransactionLifecycle\Contracts\TransactionDateGuard::class,
        'App\\Contracts\\Transactions\\TransactionDependencyChecker' => \App\Shared\TransactionLifecycle\Contracts\TransactionDependencyChecker::class,

        // TransactionLifecycle — checkers
        'App\\Services\\Transactions\\Checkers\\BaseTransactionDependencyChecker' => \App\Shared\TransactionLifecycle\Checkers\BaseTransactionDependencyChecker::class,
        'App\\Services\\Transactions\\Checkers\\CashBankTransactionDependencyChecker' => \App\Shared\TransactionLifecycle\Checkers\CashBankTransactionDependencyChecker::class,
        'App\\Services\\Transactions\\Checkers\\InventoryTransactionDependencyChecker' => \App\Shared\TransactionLifecycle\Checkers\InventoryTransactionDependencyChecker::class,
        'App\\Services\\Transactions\\Checkers\\JournalTransactionDependencyChecker' => \App\Shared\TransactionLifecycle\Checkers\JournalTransactionDependencyChecker::class,
        'App\\Services\\Transactions\\Checkers\\PurchaseTransactionDependencyChecker' => \App\Shared\TransactionLifecycle\Checkers\PurchaseTransactionDependencyChecker::class,
        'App\\Services\\Transactions\\Checkers\\SalesTransactionDependencyChecker' => \App\Shared\TransactionLifecycle\Checkers\SalesTransactionDependencyChecker::class,

        // SourceDocument
        'App\\Http\\Controllers\\Api\\Transactions\\SourceDocumentPickerController' => \App\Shared\SourceDocument\SourceDocumentPickerController::class,
        'App\\Services\\Transactions\\SourceDocumentPickerService' => \App\Shared\SourceDocument\SourceDocumentPickerService::class,
        'App\\Support\\SourceLink\\SourceLink' => \App\Shared\SourceDocument\SourceLink::class,
        'App\\Support\\SourceLink\\SourceLinkFactory' => \App\Shared\SourceDocument\SourceLinkFactory::class,
        'App\\Support\\SourceLink\\SourceModule' => \App\Shared\SourceDocument\SourceModule::class,
        'App\\Support\\SourceLink\\SourceType' => \App\Shared\SourceDocument\SourceType::class,

        // AccountMapping
        'App\\Services\\AccountMapping\\AccountMappingService' => \App\Shared\AccountMapping\AccountMappingService::class,
        'App\\Services\\AccountMapping\\AccountMappingValidator' => \App\Shared\AccountMapping\AccountMappingValidator::class,
        'App\\Support\\AccountMapping\\AccountMappingKey' => \App\Shared\AccountMapping\AccountMappingKey::class,
        'App\\Support\\AccountMapping\\AccountMappingModule' => \App\Shared\AccountMapping\AccountMappingModule::class,
        'App\\Support\\AccountMapping\\AccountMappingRequirement' => \App\Shared\AccountMapping\AccountMappingRequirement::class,

        // Permission
        'App\\Services\\Permissions\\EffectivePermissionService' => \App\Shared\Permission\EffectivePermissionService::class,
        'App\\Services\\Permissions\\PermissionCatalogService' => \App\Shared\Permission\PermissionCatalogService::class,
        'App\\Services\\Permissions\\PermissionService' => \App\Shared\Permission\PermissionService::class,

        // Tenant
        'App\\Services\\Tenant\\TenantConnectionManager' => \App\Shared\Tenant\TenantConnectionManager::class,
        'App\\Services\\Tenant\\TenantContext' => \App\Shared\Tenant\TenantContext::class,
        'App\\Services\\Tenant\\TenantMigrationService' => \App\Shared\Tenant\TenantMigrationService::class,
        'App\\Services\\Tenant\\TenantProvisioningService' => \App\Shared\Tenant\TenantProvisioningService::class,

        // Validation
        'App\\Services\\Validation\\BusinessReferenceValidator' => \App\Shared\Validation\BusinessReferenceValidator::class,

        // DataRetention
        'App\\Services\\DataRetention\\DataRetentionService' => \App\Shared\DataRetention\DataRetentionService::class,
        'App\\Services\\DataRetention\\DataRetentionValidator' => \App\Shared\DataRetention\DataRetentionValidator::class,
        'App\\Support\\DataRetention\\DataRetentionPolicy' => \App\Shared\DataRetention\DataRetentionPolicy::class,
        'App\\Support\\DataRetention\\RetentionAction' => \App\Shared\DataRetention\RetentionAction::class,
        'App\\Support\\DataRetention\\RetentionDecision' => \App\Shared\DataRetention\RetentionDecision::class,

        // Reports — DTOs (Data/Reports/*)
        'App\\Data\\Reports\\AccountLedgerFilter' => \App\Shared\Reports\Data\AccountLedgerFilter::class,
        'App\\Data\\Reports\\AccountLedgerLineData' => \App\Shared\Reports\Data\AccountLedgerLineData::class,
        'App\\Data\\Reports\\BalanceSheetFilter' => \App\Shared\Reports\Data\BalanceSheetFilter::class,
        'App\\Data\\Reports\\CashFlowFilter' => \App\Shared\Reports\Data\CashFlowFilter::class,
        'App\\Data\\Reports\\FinancialSummaryFilter' => \App\Shared\Reports\Data\FinancialSummaryFilter::class,
        'App\\Data\\Reports\\LedgerAccountSummaryData' => \App\Shared\Reports\Data\LedgerAccountSummaryData::class,
        'App\\Data\\Reports\\LedgerFilter' => \App\Shared\Reports\Data\LedgerFilter::class,
        'App\\Data\\Reports\\LedgerLineData' => \App\Shared\Reports\Data\LedgerLineData::class,
        'App\\Data\\Reports\\ProfitLossFilter' => \App\Shared\Reports\Data\ProfitLossFilter::class,
        'App\\Data\\Reports\\ReportDateRange' => \App\Shared\Reports\Data\ReportDateRange::class,
        'App\\Data\\Reports\\ReportDimensionFilter' => \App\Shared\Reports\Data\ReportDimensionFilter::class,
        'App\\Data\\Reports\\ReportMeta' => \App\Shared\Reports\Data\ReportMeta::class,
        'App\\Data\\Reports\\ReportResponse' => \App\Shared\Reports\Data\ReportResponse::class,
        'App\\Data\\Reports\\ReportTotals' => \App\Shared\Reports\Data\ReportTotals::class,
        'App\\Data\\Reports\\TrialBalanceAccountData' => \App\Shared\Reports\Data\TrialBalanceAccountData::class,
        'App\\Data\\Reports\\TrialBalanceFilter' => \App\Shared\Reports\Data\TrialBalanceFilter::class,

        // Reports — support
        'App\\Support\\Reports\\ReportVisibilityMode' => \App\Shared\Reports\ReportVisibilityMode::class,

        // Enums / Exceptions
        'App\\Enums\\SourceType' => \App\Shared\Enums\SourceType::class,
        'App\\Exceptions\\ApiException' => \App\Shared\Exceptions\ApiException::class,

        // Http — middleware & controller
        'App\\Http\\Middleware\\EnsureCompanyAccess' => \App\Shared\Http\Middleware\EnsureCompanyAccess::class,
        'App\\Http\\Middleware\\EnsurePermission' => \App\Shared\Http\Middleware\EnsurePermission::class,
        'App\\Http\\Controllers\\Api\\HealthController' => \App\Shared\Http\Controllers\HealthController::class,

        // ── Fase 1: Auth · Companies · Tenant ──
        // Service dipakai lintas layer (Console Commands) → butuh alias.
        'App\\Services\\Companies\\CompanyUserAssignmentService' => \App\Modules\Companies\Services\CompanyUserAssignmentService::class,

        // ── Fase 2: Access · Settings · Setup ──
        // CompanySettingService dipakai luas (services transaksi + tests); SetupWizardService oleh tests.
        'App\\Services\\Settings\\CompanySettingService' => \App\Modules\Settings\Services\CompanySettingService::class,
        'App\\Services\\Setup\\SetupWizardService' => \App\Modules\Setup\Services\SetupWizardService::class,

        // ── Fase 3: MasterData · Accounting · Journal ──
        // Semua 22 service foundational dipakai lintas modul (posting/period/master data
        // di-consume Sales/Purchase/CashBank/Inventory/FixedAssets/Budget/OpeningBalance).
        // MasterData (10)
        'App\\Services\\MasterData\\AccountMappingStorageService' => \App\Modules\MasterData\Services\AccountMappingStorageService::class,
        'App\\Services\\MasterData\\ChartOfAccountService' => \App\Modules\MasterData\Services\ChartOfAccountService::class,
        'App\\Services\\MasterData\\ContactService' => \App\Modules\MasterData\Services\ContactService::class,
        'App\\Services\\MasterData\\DepartmentService' => \App\Modules\MasterData\Services\DepartmentService::class,
        'App\\Services\\MasterData\\PaymentTermService' => \App\Modules\MasterData\Services\PaymentTermService::class,
        'App\\Services\\MasterData\\ProductCategoryService' => \App\Modules\MasterData\Services\ProductCategoryService::class,
        'App\\Services\\MasterData\\ProductService' => \App\Modules\MasterData\Services\ProductService::class,
        'App\\Services\\MasterData\\ProjectService' => \App\Modules\MasterData\Services\ProjectService::class,
        'App\\Services\\MasterData\\UnitService' => \App\Modules\MasterData\Services\UnitService::class,
        'App\\Services\\MasterData\\WarehouseService' => \App\Modules\MasterData\Services\WarehouseService::class,
        // Accounting (6)
        'App\\Services\\Accounting\\AccountMappingHealthService' => \App\Modules\Accounting\Services\AccountMappingHealthService::class,
        'App\\Services\\Accounting\\AnnualClosingGateService' => \App\Modules\Accounting\Services\AnnualClosingGateService::class,
        'App\\Services\\Accounting\\FiscalYearClosingService' => \App\Modules\Accounting\Services\FiscalYearClosingService::class,
        'App\\Services\\Accounting\\FiscalYearService' => \App\Modules\Accounting\Services\FiscalYearService::class,
        'App\\Services\\Accounting\\PeriodEndService' => \App\Modules\Accounting\Services\PeriodEndService::class,
        'App\\Services\\Accounting\\PeriodLockService' => \App\Modules\Accounting\Services\PeriodLockService::class,
        // Journal (6)
        'App\\Services\\Journal\\JournalEntryService' => \App\Modules\Journal\Services\JournalEntryService::class,
        'App\\Services\\Journal\\JournalLineNormalizer' => \App\Modules\Journal\Services\JournalLineNormalizer::class,
        'App\\Services\\Journal\\JournalPostingService' => \App\Modules\Journal\Services\JournalPostingService::class,
        'App\\Services\\Journal\\JournalValidationService' => \App\Modules\Journal\Services\JournalValidationService::class,
        'App\\Services\\Journal\\JournalVoidService' => \App\Modules\Journal\Services\JournalVoidService::class,
        'App\\Services\\Journal\\SystemJournalBuilder' => \App\Modules\Journal\Services\SystemJournalBuilder::class,

        // ── Fase 4: CashBank · OpeningBalance · Budget ──
        // CashBank (6)
        'App\\Services\\CashBank\\BankReconciliationService' => \App\Modules\CashBank\Services\BankReconciliationService::class,
        'App\\Services\\CashBank\\BankTransferService' => \App\Modules\CashBank\Services\BankTransferService::class,
        'App\\Services\\CashBank\\CashBankAccountService' => \App\Modules\CashBank\Services\CashBankAccountService::class,
        'App\\Services\\CashBank\\CashBankReportService' => \App\Modules\CashBank\Services\CashBankReportService::class,
        'App\\Services\\CashBank\\CashPaymentService' => \App\Modules\CashBank\Services\CashPaymentService::class,
        'App\\Services\\CashBank\\CashReceiptService' => \App\Modules\CashBank\Services\CashReceiptService::class,
        // OpeningBalance services (3)
        'App\\Services\\OpeningBalance\\OpeningBalanceBatchService' => \App\Modules\OpeningBalance\Services\OpeningBalanceBatchService::class,
        'App\\Services\\OpeningBalance\\OpeningBalanceService' => \App\Modules\OpeningBalance\Services\OpeningBalanceService::class,
        'App\\Services\\OpeningBalance\\OpeningBalanceValidator' => \App\Modules\OpeningBalance\Services\OpeningBalanceValidator::class,
        // OpeningBalance value objects (Support → modul)
        'App\\Support\\OpeningBalance\\OpeningBalanceBatch' => \App\Modules\OpeningBalance\Support\OpeningBalanceBatch::class,
        'App\\Support\\OpeningBalance\\OpeningBalanceLine' => \App\Modules\OpeningBalance\Support\OpeningBalanceLine::class,
        'App\\Support\\OpeningBalance\\OpeningBalanceType' => \App\Modules\OpeningBalance\Support\OpeningBalanceType::class,
        // Budget (5)
        'App\\Services\\Budget\\BudgetComparisonService' => \App\Modules\Budget\Services\BudgetComparisonService::class,
        'App\\Services\\Budget\\BudgetConsolidationService' => \App\Modules\Budget\Services\BudgetConsolidationService::class,
        'App\\Services\\Budget\\BudgetPeriodService' => \App\Modules\Budget\Services\BudgetPeriodService::class,
        'App\\Services\\Budget\\BudgetSubmissionService' => \App\Modules\Budget\Services\BudgetSubmissionService::class,
        'App\\Services\\Budget\\BudgetWarningService' => \App\Modules\Budget\Services\BudgetWarningService::class,

        // ── Fase 5: Inventory · Sales · Purchase ──
        // Inventory services (16)
        'App\\Services\\Inventory\\AverageCostService' => \App\Modules\Inventory\Services\AverageCostService::class,
        'App\\Services\\Inventory\\InventoryAccountMappingService' => \App\Modules\Inventory\Services\InventoryAccountMappingService::class,
        'App\\Services\\Inventory\\InventoryConfigService' => \App\Modules\Inventory\Services\InventoryConfigService::class,
        'App\\Services\\Inventory\\InventoryPurchaseIntegrationService' => \App\Modules\Inventory\Services\InventoryPurchaseIntegrationService::class,
        'App\\Services\\Inventory\\InventoryQuantityService' => \App\Modules\Inventory\Services\InventoryQuantityService::class,
        'App\\Services\\Inventory\\InventorySalesIntegrationService' => \App\Modules\Inventory\Services\InventorySalesIntegrationService::class,
        'App\\Services\\Inventory\\InventorySourceService' => \App\Modules\Inventory\Services\InventorySourceService::class,
        'App\\Services\\Inventory\\InventoryValuationService' => \App\Modules\Inventory\Services\InventoryValuationService::class,
        'App\\Services\\Inventory\\OpeningStockService' => \App\Modules\Inventory\Services\OpeningStockService::class,
        'App\\Services\\Inventory\\StockAdjustmentService' => \App\Modules\Inventory\Services\StockAdjustmentService::class,
        'App\\Services\\Inventory\\StockBalanceRebuildService' => \App\Modules\Inventory\Services\StockBalanceRebuildService::class,
        'App\\Services\\Inventory\\StockBalanceService' => \App\Modules\Inventory\Services\StockBalanceService::class,
        'App\\Services\\Inventory\\StockMovementJournalService' => \App\Modules\Inventory\Services\StockMovementJournalService::class,
        'App\\Services\\Inventory\\StockMovementService' => \App\Modules\Inventory\Services\StockMovementService::class,
        'App\\Services\\Inventory\\StockMovementValidationService' => \App\Modules\Inventory\Services\StockMovementValidationService::class,
        'App\\Services\\Inventory\\StockOpnameService' => \App\Modules\Inventory\Services\StockOpnameService::class,
        // Inventory report services (5)
        'App\\Services\\Inventory\\Reports\\InventoryAlertReportService' => \App\Modules\Inventory\Services\Reports\InventoryAlertReportService::class,
        'App\\Services\\Inventory\\Reports\\InventoryValuationReportService' => \App\Modules\Inventory\Services\Reports\InventoryValuationReportService::class,
        'App\\Services\\Inventory\\Reports\\StockBalanceReportService' => \App\Modules\Inventory\Services\Reports\StockBalanceReportService::class,
        'App\\Services\\Inventory\\Reports\\StockCardReportService' => \App\Modules\Inventory\Services\Reports\StockCardReportService::class,
        'App\\Services\\Inventory\\Reports\\StockMovementReportService' => \App\Modules\Inventory\Services\Reports\StockMovementReportService::class,
        // Inventory value objects (Support → modul)
        'App\\Support\\Inventory\\InventoryMovementStatus' => \App\Modules\Inventory\Support\InventoryMovementStatus::class,
        'App\\Support\\Inventory\\InventoryMovementType' => \App\Modules\Inventory\Support\InventoryMovementType::class,
        // Sales services (15; trait HandlesSalesDocuments di-update langsung, tak di-alias)
        'App\\Services\\Sales\\ARAgingService' => \App\Modules\Sales\Services\ARAgingService::class,
        'App\\Services\\Sales\\ARReconciliationService' => \App\Modules\Sales\Services\ARReconciliationService::class,
        'App\\Services\\Sales\\ARSubsidiaryLedgerService' => \App\Modules\Sales\Services\ARSubsidiaryLedgerService::class,
        'App\\Services\\Sales\\CustomerDepositService' => \App\Modules\Sales\Services\CustomerDepositService::class,
        'App\\Services\\Sales\\DeliveryOrderService' => \App\Modules\Sales\Services\DeliveryOrderService::class,
        'App\\Services\\Sales\\ProformaInvoiceService' => \App\Modules\Sales\Services\ProformaInvoiceService::class,
        'App\\Services\\Sales\\SalesAccountMappingService' => \App\Modules\Sales\Services\SalesAccountMappingService::class,
        'App\\Services\\Sales\\SalesAccountResolverService' => \App\Modules\Sales\Services\SalesAccountResolverService::class,
        'App\\Services\\Sales\\SalesCalculationService' => \App\Modules\Sales\Services\SalesCalculationService::class,
        'App\\Services\\Sales\\SalesInvoiceService' => \App\Modules\Sales\Services\SalesInvoiceService::class,
        'App\\Services\\Sales\\SalesOrderService' => \App\Modules\Sales\Services\SalesOrderService::class,
        'App\\Services\\Sales\\SalesQuotationService' => \App\Modules\Sales\Services\SalesQuotationService::class,
        'App\\Services\\Sales\\SalesReceiptService' => \App\Modules\Sales\Services\SalesReceiptService::class,
        'App\\Services\\Sales\\SalesReturnService' => \App\Modules\Sales\Services\SalesReturnService::class,
        'App\\Services\\Sales\\SalesSourceChainService' => \App\Modules\Sales\Services\SalesSourceChainService::class,
        'App\\Services\\Sales\\SalesStatusService' => \App\Modules\Sales\Services\SalesStatusService::class,
        // Purchase services (15; trait HandlesPurchaseDocuments di-update langsung, tak di-alias)
        'App\\Services\\Purchase\\APAgingService' => \App\Modules\Purchase\Services\APAgingService::class,
        'App\\Services\\Purchase\\APReconciliationService' => \App\Modules\Purchase\Services\APReconciliationService::class,
        'App\\Services\\Purchase\\APSubsidiaryLedgerService' => \App\Modules\Purchase\Services\APSubsidiaryLedgerService::class,
        'App\\Services\\Purchase\\GoodsReceiptService' => \App\Modules\Purchase\Services\GoodsReceiptService::class,
        'App\\Services\\Purchase\\PurchaseAccountMappingService' => \App\Modules\Purchase\Services\PurchaseAccountMappingService::class,
        'App\\Services\\Purchase\\PurchaseAccountResolverService' => \App\Modules\Purchase\Services\PurchaseAccountResolverService::class,
        'App\\Services\\Purchase\\PurchaseCalculationService' => \App\Modules\Purchase\Services\PurchaseCalculationService::class,
        'App\\Services\\Purchase\\PurchaseOrderService' => \App\Modules\Purchase\Services\PurchaseOrderService::class,
        'App\\Services\\Purchase\\PurchaseRequestService' => \App\Modules\Purchase\Services\PurchaseRequestService::class,
        'App\\Services\\Purchase\\PurchaseReturnService' => \App\Modules\Purchase\Services\PurchaseReturnService::class,
        'App\\Services\\Purchase\\PurchaseSourceChainService' => \App\Modules\Purchase\Services\PurchaseSourceChainService::class,
        'App\\Services\\Purchase\\PurchaseStatusService' => \App\Modules\Purchase\Services\PurchaseStatusService::class,
        'App\\Services\\Purchase\\VendorBillService' => \App\Modules\Purchase\Services\VendorBillService::class,
        'App\\Services\\Purchase\\VendorDepositService' => \App\Modules\Purchase\Services\VendorDepositService::class,
        'App\\Services\\Purchase\\VendorPaymentService' => \App\Modules\Purchase\Services\VendorPaymentService::class,
    ];

    public function register(): void
    {
        foreach (self::ALIASES as $legacy => $current) {
            if (! class_exists($legacy, false) && ! interface_exists($legacy, false)) {
                class_alias($current, $legacy);
            }
        }
    }
}
