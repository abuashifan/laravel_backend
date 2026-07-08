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
