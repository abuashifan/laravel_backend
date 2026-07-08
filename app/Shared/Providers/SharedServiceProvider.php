<?php

namespace App\Shared\Providers;

use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\Contracts\TransactionDateGuard;
use App\Shared\TransactionLifecycle\Contracts\TransactionDependencyChecker;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
use App\Shared\TransactionLifecycle\TransactionDependencyService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class SharedServiceProvider extends ServiceProvider
{
    /**
     * Register cross-cutting bindings (moved from AppServiceProvider in Fase 0).
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class, function () {
            return new TenantContext();
        });

        // Phase 4D placeholders (replaced by real implementations in Phase 4E/4F)
        $this->app->bind(TransactionDependencyChecker::class, TransactionDependencyService::class);
        $this->app->bind(TransactionDateGuard::class, TransactionDateGuardService::class);
    }

    /**
     * Bootstrap shared services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(database_path('migrations/central'));

        // Fase 7: morph map — kolom *_type lama menyimpan FQCN App\Models\... penuh
        // (tidak ada enforceMorphMap). Petakan FQCN lama → class baru agar data
        // morph historis (ActivityLog::subject) tetap resolve setelah model pindah namespace.
        // Semua 92 model didaftarkan sebagai superset defensif.
        Relation::morphMap([
            'App\\Models\\AccountingPeriod' => \App\Shared\Models\AccountingPeriod::class,
            'App\\Models\\ActivityLog' => \App\Shared\Models\ActivityLog::class,
            'App\\Models\\Company' => \App\Shared\Models\Company::class,
            'App\\Models\\CompanyAccountingSetting' => \App\Shared\Models\CompanyAccountingSetting::class,
            'App\\Models\\CompanyInvitation' => \App\Shared\Models\CompanyInvitation::class,
            'App\\Models\\CompanyModuleSetting' => \App\Shared\Models\CompanyModuleSetting::class,
            'App\\Models\\CompanySetupState' => \App\Shared\Models\CompanySetupState::class,
            'App\\Models\\CompanyUser' => \App\Shared\Models\CompanyUser::class,
            'App\\Models\\CompanyUserPermissionOverride' => \App\Shared\Models\CompanyUserPermissionOverride::class,
            'App\\Models\\DocumentNumberingSetting' => \App\Shared\Models\DocumentNumberingSetting::class,
            'App\\Models\\DocumentNumberSequence' => \App\Shared\Models\DocumentNumberSequence::class,
            'App\\Models\\FiscalYear' => \App\Shared\Models\FiscalYear::class,
            'App\\Models\\Permission' => \App\Shared\Models\Permission::class,
            'App\\Models\\Plan' => \App\Shared\Models\Plan::class,
            'App\\Models\\Role' => \App\Shared\Models\Role::class,
            'App\\Models\\Subscription' => \App\Shared\Models\Subscription::class,
            'App\\Models\\TenantDatabase' => \App\Shared\Models\TenantDatabase::class,
            'App\\Models\\User' => \App\Shared\Models\User::class,
            'App\\Models\\Tenant\\AccountMapping' => \App\Modules\MasterData\Models\AccountMapping::class,
            'App\\Models\\Tenant\\ChartOfAccount' => \App\Modules\MasterData\Models\ChartOfAccount::class,
            'App\\Models\\Tenant\\Contact' => \App\Modules\MasterData\Models\Contact::class,
            'App\\Models\\Tenant\\Department' => \App\Modules\MasterData\Models\Department::class,
            'App\\Models\\Tenant\\PaymentTerm' => \App\Modules\MasterData\Models\PaymentTerm::class,
            'App\\Models\\Tenant\\Product' => \App\Modules\MasterData\Models\Product::class,
            'App\\Models\\Tenant\\ProductCategory' => \App\Modules\MasterData\Models\ProductCategory::class,
            'App\\Models\\Tenant\\Project' => \App\Modules\MasterData\Models\Project::class,
            'App\\Models\\Tenant\\Unit' => \App\Modules\MasterData\Models\Unit::class,
            'App\\Models\\Tenant\\Warehouse' => \App\Modules\MasterData\Models\Warehouse::class,
            'App\\Models\\Tenant\\JournalEntry' => \App\Modules\Journal\Models\JournalEntry::class,
            'App\\Models\\Tenant\\JournalEntryLine' => \App\Modules\Journal\Models\JournalEntryLine::class,
            'App\\Models\\Tenant\\FiscalYearClosing' => \App\Modules\Accounting\Models\FiscalYearClosing::class,
            'App\\Models\\Tenant\\PeriodEndRun' => \App\Modules\Accounting\Models\PeriodEndRun::class,
            'App\\Models\\Tenant\\PeriodEndRunRoutine' => \App\Modules\Accounting\Models\PeriodEndRunRoutine::class,
            'App\\Models\\Tenant\\BankReconciliation' => \App\Modules\CashBank\Models\BankReconciliation::class,
            'App\\Models\\Tenant\\BankReconciliationLine' => \App\Modules\CashBank\Models\BankReconciliationLine::class,
            'App\\Models\\Tenant\\BankTransfer' => \App\Modules\CashBank\Models\BankTransfer::class,
            'App\\Models\\Tenant\\CashPayment' => \App\Modules\CashBank\Models\CashPayment::class,
            'App\\Models\\Tenant\\CashPaymentLine' => \App\Modules\CashBank\Models\CashPaymentLine::class,
            'App\\Models\\Tenant\\CashReceipt' => \App\Modules\CashBank\Models\CashReceipt::class,
            'App\\Models\\Tenant\\CashReceiptLine' => \App\Modules\CashBank\Models\CashReceiptLine::class,
            'App\\Models\\Tenant\\BudgetLine' => \App\Modules\Budget\Models\BudgetLine::class,
            'App\\Models\\Tenant\\BudgetPeriod' => \App\Modules\Budget\Models\BudgetPeriod::class,
            'App\\Models\\Tenant\\BudgetSubmission' => \App\Modules\Budget\Models\BudgetSubmission::class,
            'App\\Models\\Tenant\\OpeningBalanceBatch' => \App\Modules\OpeningBalance\Models\OpeningBalanceBatch::class,
            'App\\Models\\Tenant\\OpeningBalanceLine' => \App\Modules\OpeningBalance\Models\OpeningBalanceLine::class,
            'App\\Models\\Tenant\\StockAdjustment' => \App\Modules\Inventory\Models\StockAdjustment::class,
            'App\\Models\\Tenant\\StockAdjustmentLine' => \App\Modules\Inventory\Models\StockAdjustmentLine::class,
            'App\\Models\\Tenant\\StockBalance' => \App\Modules\Inventory\Models\StockBalance::class,
            'App\\Models\\Tenant\\StockMovement' => \App\Modules\Inventory\Models\StockMovement::class,
            'App\\Models\\Tenant\\StockMovementLine' => \App\Modules\Inventory\Models\StockMovementLine::class,
            'App\\Models\\Tenant\\StockOpname' => \App\Modules\Inventory\Models\StockOpname::class,
            'App\\Models\\Tenant\\StockOpnameLine' => \App\Modules\Inventory\Models\StockOpnameLine::class,
            'App\\Models\\Tenant\\CustomerDeposit' => \App\Modules\Sales\Models\CustomerDeposit::class,
            'App\\Models\\Tenant\\CustomerDepositAllocation' => \App\Modules\Sales\Models\CustomerDepositAllocation::class,
            'App\\Models\\Tenant\\DeliveryOrder' => \App\Modules\Sales\Models\DeliveryOrder::class,
            'App\\Models\\Tenant\\DeliveryOrderLine' => \App\Modules\Sales\Models\DeliveryOrderLine::class,
            'App\\Models\\Tenant\\ProformaInvoice' => \App\Modules\Sales\Models\ProformaInvoice::class,
            'App\\Models\\Tenant\\ProformaInvoiceLine' => \App\Modules\Sales\Models\ProformaInvoiceLine::class,
            'App\\Models\\Tenant\\SalesInvoice' => \App\Modules\Sales\Models\SalesInvoice::class,
            'App\\Models\\Tenant\\SalesInvoiceLine' => \App\Modules\Sales\Models\SalesInvoiceLine::class,
            'App\\Models\\Tenant\\SalesOrder' => \App\Modules\Sales\Models\SalesOrder::class,
            'App\\Models\\Tenant\\SalesOrderLine' => \App\Modules\Sales\Models\SalesOrderLine::class,
            'App\\Models\\Tenant\\SalesQuotation' => \App\Modules\Sales\Models\SalesQuotation::class,
            'App\\Models\\Tenant\\SalesQuotationLine' => \App\Modules\Sales\Models\SalesQuotationLine::class,
            'App\\Models\\Tenant\\SalesReceipt' => \App\Modules\Sales\Models\SalesReceipt::class,
            'App\\Models\\Tenant\\SalesReceiptLine' => \App\Modules\Sales\Models\SalesReceiptLine::class,
            'App\\Models\\Tenant\\SalesReturn' => \App\Modules\Sales\Models\SalesReturn::class,
            'App\\Models\\Tenant\\SalesReturnLine' => \App\Modules\Sales\Models\SalesReturnLine::class,
            'App\\Models\\Tenant\\GoodsReceipt' => \App\Modules\Purchase\Models\GoodsReceipt::class,
            'App\\Models\\Tenant\\GoodsReceiptLine' => \App\Modules\Purchase\Models\GoodsReceiptLine::class,
            'App\\Models\\Tenant\\PurchaseOrder' => \App\Modules\Purchase\Models\PurchaseOrder::class,
            'App\\Models\\Tenant\\PurchaseOrderLine' => \App\Modules\Purchase\Models\PurchaseOrderLine::class,
            'App\\Models\\Tenant\\PurchaseRequest' => \App\Modules\Purchase\Models\PurchaseRequest::class,
            'App\\Models\\Tenant\\PurchaseRequestLine' => \App\Modules\Purchase\Models\PurchaseRequestLine::class,
            'App\\Models\\Tenant\\PurchaseReturn' => \App\Modules\Purchase\Models\PurchaseReturn::class,
            'App\\Models\\Tenant\\PurchaseReturnLine' => \App\Modules\Purchase\Models\PurchaseReturnLine::class,
            'App\\Models\\Tenant\\VendorBill' => \App\Modules\Purchase\Models\VendorBill::class,
            'App\\Models\\Tenant\\VendorBillLine' => \App\Modules\Purchase\Models\VendorBillLine::class,
            'App\\Models\\Tenant\\VendorDeposit' => \App\Modules\Purchase\Models\VendorDeposit::class,
            'App\\Models\\Tenant\\VendorDepositAllocation' => \App\Modules\Purchase\Models\VendorDepositAllocation::class,
            'App\\Models\\Tenant\\VendorPayment' => \App\Modules\Purchase\Models\VendorPayment::class,
            'App\\Models\\Tenant\\VendorPaymentLine' => \App\Modules\Purchase\Models\VendorPaymentLine::class,
            'App\\Models\\Tenant\\FixedAsset' => \App\Modules\FixedAssets\Models\FixedAsset::class,
            'App\\Models\\Tenant\\FixedAssetAcquisition' => \App\Modules\FixedAssets\Models\FixedAssetAcquisition::class,
            'App\\Models\\Tenant\\FixedAssetCategory' => \App\Modules\FixedAssets\Models\FixedAssetCategory::class,
            'App\\Models\\Tenant\\FixedAssetDepreciationRun' => \App\Modules\FixedAssets\Models\FixedAssetDepreciationRun::class,
            'App\\Models\\Tenant\\FixedAssetDepreciationRunLine' => \App\Modules\FixedAssets\Models\FixedAssetDepreciationRunLine::class,
            'App\\Models\\Tenant\\FixedAssetDepreciationSchedule' => \App\Modules\FixedAssets\Models\FixedAssetDepreciationSchedule::class,
            'App\\Models\\Tenant\\FixedAssetDisposal' => \App\Modules\FixedAssets\Models\FixedAssetDisposal::class,
            'App\\Models\\Tenant\\FixedAssetTransaction' => \App\Modules\FixedAssets\Models\FixedAssetTransaction::class,
            'App\\Models\\Tenant\\TenantAuditLog' => \App\Shared\Models\TenantAuditLog::class,
            'App\\Models\\Tenant\\TransactionRevision' => \App\Shared\Models\TransactionRevision::class,
        ]);
    }
}
