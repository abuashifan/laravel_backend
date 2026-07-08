<?php

namespace App\Shared\Providers;

use App\Modules\Accounting\Models\FiscalYearClosing;
use App\Modules\Accounting\Models\PeriodEndRun;
use App\Modules\Accounting\Models\PeriodEndRunRoutine;
use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\CashBank\Models\BankReconciliation;
use App\Modules\CashBank\Models\BankReconciliationLine;
use App\Modules\CashBank\Models\BankTransfer;
use App\Modules\CashBank\Models\CashPayment;
use App\Modules\CashBank\Models\CashPaymentLine;
use App\Modules\CashBank\Models\CashReceipt;
use App\Modules\CashBank\Models\CashReceiptLine;
use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Models\FixedAssetAcquisition;
use App\Modules\FixedAssets\Models\FixedAssetCategory;
use App\Modules\FixedAssets\Models\FixedAssetDepreciationRun;
use App\Modules\FixedAssets\Models\FixedAssetDepreciationRunLine;
use App\Modules\FixedAssets\Models\FixedAssetDepreciationSchedule;
use App\Modules\FixedAssets\Models\FixedAssetDisposal;
use App\Modules\FixedAssets\Models\FixedAssetTransaction;
use App\Modules\Inventory\Models\StockAdjustment;
use App\Modules\Inventory\Models\StockAdjustmentLine;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\StockMovementLine;
use App\Modules\Inventory\Models\StockOpname;
use App\Modules\Inventory\Models\StockOpnameLine;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Journal\Models\JournalEntryLine;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Department;
use App\Modules\MasterData\Models\PaymentTerm;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\ProductCategory;
use App\Modules\MasterData\Models\Project;
use App\Modules\MasterData\Models\Unit;
use App\Modules\MasterData\Models\Warehouse;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Modules\OpeningBalance\Models\OpeningBalanceLine;
use App\Modules\Purchase\Models\GoodsReceipt;
use App\Modules\Purchase\Models\GoodsReceiptLine;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseOrderLine;
use App\Modules\Purchase\Models\PurchaseRequest;
use App\Modules\Purchase\Models\PurchaseRequestLine;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\PurchaseReturnLine;
use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Models\VendorBillLine;
use App\Modules\Purchase\Models\VendorDeposit;
use App\Modules\Purchase\Models\VendorDepositAllocation;
use App\Modules\Purchase\Models\VendorPayment;
use App\Modules\Purchase\Models\VendorPaymentLine;
use App\Modules\Sales\Models\CustomerDeposit;
use App\Modules\Sales\Models\CustomerDepositAllocation;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\DeliveryOrderLine;
use App\Modules\Sales\Models\ProformaInvoice;
use App\Modules\Sales\Models\ProformaInvoiceLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Models\SalesQuotationLine;
use App\Modules\Sales\Models\SalesReceipt;
use App\Modules\Sales\Models\SalesReceiptLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Shared\Models\AccountingPeriod;
use App\Shared\Models\ActivityLog;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyAccountingSetting;
use App\Shared\Models\CompanyInvitation;
use App\Shared\Models\CompanyModuleSetting;
use App\Shared\Models\CompanySetupState;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\CompanyUserPermissionOverride;
use App\Shared\Models\DocumentNumberingSetting;
use App\Shared\Models\DocumentNumberSequence;
use App\Shared\Models\FiscalYear;
use App\Shared\Models\Permission;
use App\Shared\Models\Plan;
use App\Shared\Models\Role;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantAuditLog;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\TransactionRevision;
use App\Shared\Models\User;
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
            return new TenantContext;
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

        // Fase 7: morph map — kolom *_type lama menyimpan FQCN model lama (prefix App\ Models)
        // (tidak ada enforceMorphMap). Petakan FQCN lama → class baru agar data
        // morph historis (ActivityLog::subject) tetap resolve setelah model pindah namespace.
        // Semua 92 model didaftarkan sebagai superset defensif.
        Relation::morphMap([
            'App\\Models\\AccountingPeriod' => AccountingPeriod::class,
            'App\\Models\\ActivityLog' => ActivityLog::class,
            'App\\Models\\Company' => Company::class,
            'App\\Models\\CompanyAccountingSetting' => CompanyAccountingSetting::class,
            'App\\Models\\CompanyInvitation' => CompanyInvitation::class,
            'App\\Models\\CompanyModuleSetting' => CompanyModuleSetting::class,
            'App\\Models\\CompanySetupState' => CompanySetupState::class,
            'App\\Models\\CompanyUser' => CompanyUser::class,
            'App\\Models\\CompanyUserPermissionOverride' => CompanyUserPermissionOverride::class,
            'App\\Models\\DocumentNumberingSetting' => DocumentNumberingSetting::class,
            'App\\Models\\DocumentNumberSequence' => DocumentNumberSequence::class,
            'App\\Models\\FiscalYear' => FiscalYear::class,
            'App\\Models\\Permission' => Permission::class,
            'App\\Models\\Plan' => Plan::class,
            'App\\Models\\Role' => Role::class,
            'App\\Models\\Subscription' => Subscription::class,
            'App\\Models\\TenantDatabase' => TenantDatabase::class,
            'App\\Models\\User' => User::class,
            'App\\Models\\Tenant\\AccountMapping' => AccountMapping::class,
            'App\\Models\\Tenant\\ChartOfAccount' => ChartOfAccount::class,
            'App\\Models\\Tenant\\Contact' => Contact::class,
            'App\\Models\\Tenant\\Department' => Department::class,
            'App\\Models\\Tenant\\PaymentTerm' => PaymentTerm::class,
            'App\\Models\\Tenant\\Product' => Product::class,
            'App\\Models\\Tenant\\ProductCategory' => ProductCategory::class,
            'App\\Models\\Tenant\\Project' => Project::class,
            'App\\Models\\Tenant\\Unit' => Unit::class,
            'App\\Models\\Tenant\\Warehouse' => Warehouse::class,
            'App\\Models\\Tenant\\JournalEntry' => JournalEntry::class,
            'App\\Models\\Tenant\\JournalEntryLine' => JournalEntryLine::class,
            'App\\Models\\Tenant\\FiscalYearClosing' => FiscalYearClosing::class,
            'App\\Models\\Tenant\\PeriodEndRun' => PeriodEndRun::class,
            'App\\Models\\Tenant\\PeriodEndRunRoutine' => PeriodEndRunRoutine::class,
            'App\\Models\\Tenant\\BankReconciliation' => BankReconciliation::class,
            'App\\Models\\Tenant\\BankReconciliationLine' => BankReconciliationLine::class,
            'App\\Models\\Tenant\\BankTransfer' => BankTransfer::class,
            'App\\Models\\Tenant\\CashPayment' => CashPayment::class,
            'App\\Models\\Tenant\\CashPaymentLine' => CashPaymentLine::class,
            'App\\Models\\Tenant\\CashReceipt' => CashReceipt::class,
            'App\\Models\\Tenant\\CashReceiptLine' => CashReceiptLine::class,
            'App\\Models\\Tenant\\BudgetLine' => BudgetLine::class,
            'App\\Models\\Tenant\\BudgetPeriod' => BudgetPeriod::class,
            'App\\Models\\Tenant\\BudgetSubmission' => BudgetSubmission::class,
            'App\\Models\\Tenant\\OpeningBalanceBatch' => OpeningBalanceBatch::class,
            'App\\Models\\Tenant\\OpeningBalanceLine' => OpeningBalanceLine::class,
            'App\\Models\\Tenant\\StockAdjustment' => StockAdjustment::class,
            'App\\Models\\Tenant\\StockAdjustmentLine' => StockAdjustmentLine::class,
            'App\\Models\\Tenant\\StockBalance' => StockBalance::class,
            'App\\Models\\Tenant\\StockMovement' => StockMovement::class,
            'App\\Models\\Tenant\\StockMovementLine' => StockMovementLine::class,
            'App\\Models\\Tenant\\StockOpname' => StockOpname::class,
            'App\\Models\\Tenant\\StockOpnameLine' => StockOpnameLine::class,
            'App\\Models\\Tenant\\CustomerDeposit' => CustomerDeposit::class,
            'App\\Models\\Tenant\\CustomerDepositAllocation' => CustomerDepositAllocation::class,
            'App\\Models\\Tenant\\DeliveryOrder' => DeliveryOrder::class,
            'App\\Models\\Tenant\\DeliveryOrderLine' => DeliveryOrderLine::class,
            'App\\Models\\Tenant\\ProformaInvoice' => ProformaInvoice::class,
            'App\\Models\\Tenant\\ProformaInvoiceLine' => ProformaInvoiceLine::class,
            'App\\Models\\Tenant\\SalesInvoice' => SalesInvoice::class,
            'App\\Models\\Tenant\\SalesInvoiceLine' => SalesInvoiceLine::class,
            'App\\Models\\Tenant\\SalesOrder' => SalesOrder::class,
            'App\\Models\\Tenant\\SalesOrderLine' => SalesOrderLine::class,
            'App\\Models\\Tenant\\SalesQuotation' => SalesQuotation::class,
            'App\\Models\\Tenant\\SalesQuotationLine' => SalesQuotationLine::class,
            'App\\Models\\Tenant\\SalesReceipt' => SalesReceipt::class,
            'App\\Models\\Tenant\\SalesReceiptLine' => SalesReceiptLine::class,
            'App\\Models\\Tenant\\SalesReturn' => SalesReturn::class,
            'App\\Models\\Tenant\\SalesReturnLine' => SalesReturnLine::class,
            'App\\Models\\Tenant\\GoodsReceipt' => GoodsReceipt::class,
            'App\\Models\\Tenant\\GoodsReceiptLine' => GoodsReceiptLine::class,
            'App\\Models\\Tenant\\PurchaseOrder' => PurchaseOrder::class,
            'App\\Models\\Tenant\\PurchaseOrderLine' => PurchaseOrderLine::class,
            'App\\Models\\Tenant\\PurchaseRequest' => PurchaseRequest::class,
            'App\\Models\\Tenant\\PurchaseRequestLine' => PurchaseRequestLine::class,
            'App\\Models\\Tenant\\PurchaseReturn' => PurchaseReturn::class,
            'App\\Models\\Tenant\\PurchaseReturnLine' => PurchaseReturnLine::class,
            'App\\Models\\Tenant\\VendorBill' => VendorBill::class,
            'App\\Models\\Tenant\\VendorBillLine' => VendorBillLine::class,
            'App\\Models\\Tenant\\VendorDeposit' => VendorDeposit::class,
            'App\\Models\\Tenant\\VendorDepositAllocation' => VendorDepositAllocation::class,
            'App\\Models\\Tenant\\VendorPayment' => VendorPayment::class,
            'App\\Models\\Tenant\\VendorPaymentLine' => VendorPaymentLine::class,
            'App\\Models\\Tenant\\FixedAsset' => FixedAsset::class,
            'App\\Models\\Tenant\\FixedAssetAcquisition' => FixedAssetAcquisition::class,
            'App\\Models\\Tenant\\FixedAssetCategory' => FixedAssetCategory::class,
            'App\\Models\\Tenant\\FixedAssetDepreciationRun' => FixedAssetDepreciationRun::class,
            'App\\Models\\Tenant\\FixedAssetDepreciationRunLine' => FixedAssetDepreciationRunLine::class,
            'App\\Models\\Tenant\\FixedAssetDepreciationSchedule' => FixedAssetDepreciationSchedule::class,
            'App\\Models\\Tenant\\FixedAssetDisposal' => FixedAssetDisposal::class,
            'App\\Models\\Tenant\\FixedAssetTransaction' => FixedAssetTransaction::class,
            'App\\Models\\Tenant\\TenantAuditLog' => TenantAuditLog::class,
            'App\\Models\\Tenant\\TransactionRevision' => TransactionRevision::class,
        ]);
    }
}
