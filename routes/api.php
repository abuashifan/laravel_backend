<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Auth\AuthController;
use App\Http\Controllers\Api\Auth\PermissionController;
use App\Http\Controllers\Api\Accounting\FiscalYearStatusController;
use App\Http\Controllers\Api\Accounting\FiscalYearClosingController;
use App\Http\Controllers\Api\Accounting\PeriodLockController;
use App\Http\Controllers\Api\Companies\CompanyController;
use App\Http\Controllers\Api\Settings\CompanySettingController;
use App\Http\Controllers\Api\Tenant\TenantContextTestController;
use App\Http\Controllers\Api\Transactions\SourceDocumentPickerController;
use App\Http\Controllers\Api\MasterData\AccountMappingController;
use App\Http\Controllers\Api\MasterData\ChartOfAccountController;
use App\Http\Controllers\Api\MasterData\ContactController;
use App\Http\Controllers\Api\MasterData\ProductCategoryController;
use App\Http\Controllers\Api\MasterData\ProductController;
use App\Http\Controllers\Api\MasterData\UnitController;
use App\Http\Controllers\Api\MasterData\WarehouseController;
use App\Http\Controllers\Api\MasterData\DepartmentController;
use App\Http\Controllers\Api\MasterData\ProjectController;
use App\Http\Controllers\Api\MasterData\PaymentTermController;
use App\Http\Controllers\Api\Journal\JournalEntryController;
use App\Http\Controllers\Api\Reports\GeneralLedgerController;
use App\Http\Controllers\Api\Reports\AccountLedgerDetailController;
use App\Http\Controllers\Api\Reports\TrialBalanceController;
use App\Http\Controllers\Api\Reports\ProfitLossController;
use App\Http\Controllers\Api\Reports\BalanceSheetController;
use App\Http\Controllers\Api\Reports\CashFlowController;
use App\Http\Controllers\Api\Reports\FinancialSummaryController;
use App\Http\Controllers\Api\CashBank\CashBankAccountController;
use App\Http\Controllers\Api\CashBank\CashReceiptController;
use App\Http\Controllers\Api\CashBank\CashPaymentController;
use App\Http\Controllers\Api\CashBank\BankTransferController;
use App\Http\Controllers\Api\CashBank\BankReconciliationController;
use App\Http\Controllers\Api\CashBank\CashBankReportController;
use App\Http\Controllers\Api\Inventory\StockMovementController;
use App\Http\Controllers\Api\Inventory\StockBalanceController;
use App\Http\Controllers\Api\Inventory\InventoryValuationController;
use App\Http\Controllers\Api\Inventory\StockAdjustmentController;
use App\Http\Controllers\Api\Inventory\StockOpnameController;
use App\Http\Controllers\Api\Inventory\InventoryReportController;
use App\Http\Controllers\Api\Sales\DeliveryOrderController;
use App\Http\Controllers\Api\Sales\AccountsReceivableController;
use App\Http\Controllers\Api\Sales\BillingInvoiceController;
use App\Http\Controllers\Api\Sales\CustomerDepositController;
use App\Http\Controllers\Api\Sales\ProformaInvoiceController;
use App\Http\Controllers\Api\Sales\SalesReceiptController;
use App\Http\Controllers\Api\Sales\SalesReturnController;
use App\Http\Controllers\Api\Sales\SalesInvoiceController;
use App\Http\Controllers\Api\Sales\SalesOrderController;
use App\Http\Controllers\Api\Sales\SalesQuotationController;
use App\Http\Controllers\Api\Purchase\PurchaseRequestController;
use App\Http\Controllers\Api\Purchase\PurchaseOrderController;
use App\Http\Controllers\Api\Purchase\GoodsReceiptController;
use App\Http\Controllers\Api\Purchase\VendorBillController;
use App\Http\Controllers\Api\Purchase\VendorDepositController;
use App\Http\Controllers\Api\Purchase\VendorPaymentController;
use App\Http\Controllers\Api\Purchase\PurchaseReturnController;
use App\Http\Controllers\Api\Purchase\AccountsPayableController;
use App\Http\Controllers\Api\Access\AccessAuditController;
use App\Http\Controllers\Api\Access\CompanyInvitationAccessController;
use App\Http\Controllers\Api\Access\CompanyUserAccessController;
use App\Http\Controllers\Api\Access\PermissionCatalogController;
use App\Http\Controllers\Api\Access\RoleAccessController;

Route::get('/health', [HealthController::class, 'index']);

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies/select', [CompanyController::class, 'select']);
});

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    Route::get('/tenant-context-test', TenantContextTestController::class);

    Route::get('/auth/permissions', [PermissionController::class, 'index']);

    Route::get('/settings/company/workflow', [CompanySettingController::class, 'workflow']);
    Route::get('/settings/company', [CompanySettingController::class, 'show'])
        ->middleware('permission:settings.company.view');
    Route::patch('/settings/company/accounting', [CompanySettingController::class, 'updateAccounting'])
        ->middleware('permission:settings.company.edit');
    Route::patch('/settings/company/modules', [CompanySettingController::class, 'updateModules'])
        ->middleware('permission:settings.company.edit');
    Route::patch('/settings/company/transaction-defaults', [CompanySettingController::class, 'updateTransactionDefaults'])
        ->middleware('permission:settings.company.edit');
});

Route::middleware(['auth:sanctum', 'company.access'])->prefix('access')->group(function () {
    Route::get('/users', [CompanyUserAccessController::class, 'index'])->middleware('permission:access.users.view');
    Route::get('/company-users', [CompanyUserAccessController::class, 'index'])->middleware('permission:access.users.view');
    Route::get('/company-users/{companyUserId}', [CompanyUserAccessController::class, 'show'])->middleware('permission:access.users.view');
    Route::patch('/company-users/{companyUserId}/role', [CompanyUserAccessController::class, 'updateRole'])->middleware('permission:access.users.manage');
    Route::patch('/company-users/{companyUserId}/deactivate', [CompanyUserAccessController::class, 'deactivate'])->middleware('permission:access.users.deactivate');
    Route::patch('/company-users/{companyUserId}/reactivate', [CompanyUserAccessController::class, 'activate'])->middleware('permission:access.users.manage');
    Route::patch('/company-users/{companyUserId}/remove', [CompanyUserAccessController::class, 'remove'])->middleware('permission:access.users.remove');

    Route::get('/permission-catalog', PermissionCatalogController::class)->middleware('permission:access.permissions.view');
    Route::get('/permissions/catalog', PermissionCatalogController::class)->middleware('permission:access.permissions.view');
    Route::get('/users/{companyUserId}/permissions', [CompanyUserAccessController::class, 'permissions'])->middleware('permission:access.permissions.view');
    Route::put('/users/{companyUserId}/permissions', [CompanyUserAccessController::class, 'updatePermissions'])->middleware('permission:access.permissions.manage');
    Route::post('/users/{companyUserId}/copy-access', [CompanyUserAccessController::class, 'copyAccess'])->middleware('permission:access.permissions.manage');
    Route::post('/users/{companyUserId}/reset-permissions', [CompanyUserAccessController::class, 'resetPermissions'])->middleware('permission:access.permissions.manage');

    Route::get('/roles', [RoleAccessController::class, 'index'])->middleware('permission:access.roles.view');
    Route::post('/roles', [RoleAccessController::class, 'store'])->middleware('permission:access.roles.create');
    Route::get('/roles/{roleId}', [RoleAccessController::class, 'show'])->middleware('permission:access.roles.view');
    Route::patch('/roles/{roleId}', [RoleAccessController::class, 'update'])->middleware('permission:access.roles.edit');
    Route::post('/roles/{roleId}/clone', [RoleAccessController::class, 'cloneRole'])->middleware('permission:access.roles.clone');
    Route::get('/roles/{roleId}/permissions', [RoleAccessController::class, 'show'])->middleware('permission:access.permissions.view');
    Route::put('/roles/{roleId}/permissions', [RoleAccessController::class, 'updatePermissions'])->middleware('permission:access.permissions.manage');
    Route::patch('/roles/{roleId}/deactivate', [RoleAccessController::class, 'deactivate'])->middleware('permission:access.roles.deactivate');
    Route::patch('/roles/{roleId}/reactivate', [RoleAccessController::class, 'activate'])->middleware('permission:access.roles.edit');

    Route::get('/invitations', [CompanyInvitationAccessController::class, 'index'])->middleware('permission:access.invitations.view');
    Route::post('/invitations', [CompanyInvitationAccessController::class, 'store'])->middleware('permission:access.invitations.create');
    Route::post('/invitations/{id}/resend', [CompanyInvitationAccessController::class, 'resend'])->middleware('permission:access.invitations.resend');
    Route::post('/invitations/{id}/revoke', [CompanyInvitationAccessController::class, 'revoke'])->middleware('permission:access.invitations.revoke');

    Route::get('/audit', AccessAuditController::class)->middleware('permission:access.audit.view');
});

Route::middleware(['auth:sanctum', 'company.access', 'permission:dashboard.view'])->group(function () {
    Route::get('/accounting/fiscal-year/status', FiscalYearStatusController::class);
});

Route::middleware(['auth:sanctum', 'company.access'])->prefix('accounting')->group(function () {
    Route::get('/fiscal-years/{id}/closing-preview', [FiscalYearClosingController::class, 'preview'])
        ->middleware('permission:fiscal_year.view');
    Route::get('/fiscal-years/{id}/closing-checklist', [FiscalYearClosingController::class, 'checklist'])
        ->middleware('permission:fiscal_year.closing_wizard');
    Route::post('/fiscal-years/{id}/close', [FiscalYearClosingController::class, 'close'])
        ->middleware('permission:fiscal_year.close');
    Route::post('/fiscal-years/{id}/reopen', [FiscalYearClosingController::class, 'reopen'])
        ->middleware('permission:fiscal_year.reopen');

    Route::get('/period-locks/status', [PeriodLockController::class, 'status'])
        ->middleware('permission:fiscal_year.view');
    Route::patch('/period-locks', [PeriodLockController::class, 'update'])
        ->middleware('permission:fiscal_year.lock_manage');
});

Route::middleware(['auth:sanctum', 'company.access'])->prefix('cash-bank')->group(function () {
    Route::get('/accounts', [CashBankAccountController::class, 'index'])
        ->middleware('permission:cash_bank.view');

    Route::get('/cash-receipts', [CashReceiptController::class, 'index'])
        ->middleware('permission:cash_bank.view');
    Route::post('/cash-receipts', [CashReceiptController::class, 'store'])
        ->middleware('permission:cash_bank.create');
    Route::get('/cash-receipts/{id}', [CashReceiptController::class, 'show'])
        ->middleware('permission:cash_bank.view');
    Route::patch('/cash-receipts/{id}/post', [CashReceiptController::class, 'post'])
        ->middleware('permission:cash_bank.post');
    Route::patch('/cash-receipts/{id}/void', [CashReceiptController::class, 'void'])
        ->middleware('permission:cash_bank.void');

    Route::get('/cash-payments', [CashPaymentController::class, 'index'])
        ->middleware('permission:cash_bank.view');
    Route::post('/cash-payments', [CashPaymentController::class, 'store'])
        ->middleware('permission:cash_bank.create');
    Route::get('/cash-payments/{id}', [CashPaymentController::class, 'show'])
        ->middleware('permission:cash_bank.view');
    Route::patch('/cash-payments/{id}/post', [CashPaymentController::class, 'post'])
        ->middleware('permission:cash_bank.post');
    Route::patch('/cash-payments/{id}/void', [CashPaymentController::class, 'void'])
        ->middleware('permission:cash_bank.void');

    Route::get('/bank-transfers', [BankTransferController::class, 'index'])
        ->middleware('permission:cash_bank.view');
    Route::post('/bank-transfers', [BankTransferController::class, 'store'])
        ->middleware('permission:cash_bank.transfer');
    Route::get('/bank-transfers/{id}', [BankTransferController::class, 'show'])
        ->middleware('permission:cash_bank.view');
    Route::patch('/bank-transfers/{id}/post', [BankTransferController::class, 'post'])
        ->middleware('permission:cash_bank.post');
    Route::patch('/bank-transfers/{id}/void', [BankTransferController::class, 'void'])
        ->middleware('permission:cash_bank.void');

    Route::get('/bank-reconciliations', [BankReconciliationController::class, 'index'])
        ->middleware('permission:cash_bank.view');
    Route::post('/bank-reconciliations', [BankReconciliationController::class, 'store'])
        ->middleware('permission:cash_bank.create');
    Route::get('/bank-reconciliations/{id}', [BankReconciliationController::class, 'show'])
        ->middleware('permission:cash_bank.view');
    Route::patch('/bank-reconciliations/{id}', [BankReconciliationController::class, 'update'])
        ->middleware('permission:cash_bank.edit');
    Route::post('/bank-reconciliations/{id}/refresh-lines', [BankReconciliationController::class, 'refreshLines'])
        ->middleware('permission:cash_bank.edit');
    Route::post('/bank-reconciliations/{id}/mark-lines', [BankReconciliationController::class, 'markLines'])
        ->middleware('permission:cash_bank.edit');

    Route::get('/reports/account-statement', [CashBankReportController::class, 'accountStatement'])
        ->middleware('permission:cash_bank.view');
});

Route::middleware(['auth:sanctum', 'company.access'])->prefix('inventory')->group(function () {
    Route::get('/stock-balances', [StockBalanceController::class, 'index'])
        ->middleware('permission:inventory.stock.view');
    Route::get('/stock-balances/product/{productId}', [StockBalanceController::class, 'byProduct'])
        ->middleware('permission:inventory.stock.view');
    Route::get('/stock-balances/warehouse/{warehouseId}', [StockBalanceController::class, 'byWarehouse'])
        ->middleware('permission:inventory.stock.view');

    Route::get('/stock-movements', [StockMovementController::class, 'index'])
        ->middleware('permission:inventory.movements.view');
    Route::post('/stock-movements', [StockMovementController::class, 'store'])
        ->middleware('permission:inventory.movements.create');
    Route::get('/stock-movements/{id}', [StockMovementController::class, 'show'])
        ->middleware('permission:inventory.movements.view');
    Route::patch('/stock-movements/{id}/post', [StockMovementController::class, 'post'])
        ->middleware('permission:inventory.movements.post');
    Route::patch('/stock-movements/{id}/void', [StockMovementController::class, 'void'])
        ->middleware('permission:inventory.movements.void');

    Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])
        ->middleware('permission:inventory.adjustments.view');
    Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])
        ->middleware('permission:inventory.adjustments.create');
    Route::get('/stock-adjustments/{id}', [StockAdjustmentController::class, 'show'])
        ->middleware('permission:inventory.adjustments.view');
    Route::patch('/stock-adjustments/{id}', [StockAdjustmentController::class, 'update'])
        ->middleware('permission:inventory.adjustments.edit');
    Route::patch('/stock-adjustments/{id}/approve', [StockAdjustmentController::class, 'approve'])
        ->middleware('permission:inventory.adjustments.approve');
    Route::patch('/stock-adjustments/{id}/post', [StockAdjustmentController::class, 'post'])
        ->middleware('permission:inventory.adjustments.post');
    Route::patch('/stock-adjustments/{id}/void', [StockAdjustmentController::class, 'void'])
        ->middleware('permission:inventory.adjustments.void');

    Route::get('/stock-opnames', [StockOpnameController::class, 'index'])
        ->middleware('permission:inventory.opname.view');
    Route::post('/stock-opnames', [StockOpnameController::class, 'store'])
        ->middleware('permission:inventory.opname.create');
    Route::get('/stock-opnames/{id}', [StockOpnameController::class, 'show'])
        ->middleware('permission:inventory.opname.view');
    Route::post('/stock-opnames/{id}/generate-lines', [StockOpnameController::class, 'generateLines'])
        ->middleware('permission:inventory.opname.edit');
    Route::patch('/stock-opnames/{id}/lines/{lineId}', [StockOpnameController::class, 'updateLine'])
        ->middleware('permission:inventory.opname.edit');
    Route::patch('/stock-opnames/{id}/counted', [StockOpnameController::class, 'markCounted'])
        ->middleware('permission:inventory.opname.edit');
    Route::patch('/stock-opnames/{id}/finalize', [StockOpnameController::class, 'finalize'])
        ->middleware('permission:inventory.opname.finalize');
    Route::patch('/stock-opnames/{id}/void', [StockOpnameController::class, 'void'])
        ->middleware('permission:inventory.opname.finalize');

    Route::prefix('reports')->group(function () {
        Route::get('/stock-balances', [InventoryReportController::class, 'stockBalances'])->middleware('permission:inventory.reports.view');
        Route::get('/stock-movements', [InventoryReportController::class, 'stockMovements'])->middleware('permission:inventory.reports.view');
        Route::get('/stock-card', [InventoryReportController::class, 'stockCard'])->middleware('permission:inventory.reports.view');
        Route::get('/valuation', [InventoryReportController::class, 'valuation'])->middleware('permission:inventory.reports.view');
        Route::get('/low-stock', [InventoryReportController::class, 'lowStock'])->middleware('permission:inventory.reports.view');
        Route::get('/negative-stock', [InventoryReportController::class, 'negativeStock'])->middleware('permission:inventory.reports.view');
    });

    Route::get('/valuation', [InventoryValuationController::class, 'current'])
        ->middleware('permission:inventory.valuation.view');
    Route::get('/valuation/as-of', [InventoryValuationController::class, 'asOf'])
        ->middleware('permission:inventory.valuation.view');
    Route::get('/valuation/products/{productId}', [InventoryValuationController::class, 'byProduct'])
        ->middleware('permission:inventory.valuation.view');
    Route::get('/valuation/warehouses/{warehouseId}', [InventoryValuationController::class, 'byWarehouse'])
        ->middleware('permission:inventory.valuation.view');
});

// NOTE: Phase 1B demo endpoint `/api/my-companies-demo` has been disabled in Phase 2A.

Route::middleware(['auth:sanctum', 'company.access'])->prefix('master-data')->group(function () {
    // Chart of Accounts
    Route::get('/chart-of-accounts', [ChartOfAccountController::class, 'index'])->middleware('permission:coa.view');
    Route::post('/chart-of-accounts', [ChartOfAccountController::class, 'store'])->middleware('permission:coa.create');
    Route::get('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'show'])->middleware('permission:coa.view');
    Route::patch('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'update'])->middleware('permission:coa.edit');
    Route::patch('/chart-of-accounts/{id}/deactivate', [ChartOfAccountController::class, 'deactivate'])->middleware('permission:coa.deactivate');
    Route::patch('/chart-of-accounts/{id}/activate', [ChartOfAccountController::class, 'activate'])->middleware('permission:coa.edit');

    // Contacts
    Route::get('/contacts', [ContactController::class, 'index'])->middleware('permission:contacts.view');
    Route::post('/contacts', [ContactController::class, 'store'])->middleware('permission:contacts.create');
    Route::get('/contacts/{id}', [ContactController::class, 'show'])->middleware('permission:contacts.view');
    Route::patch('/contacts/{id}', [ContactController::class, 'update'])->middleware('permission:contacts.edit');
    Route::patch('/contacts/{id}/deactivate', [ContactController::class, 'deactivate'])->middleware('permission:contacts.deactivate');
    Route::patch('/contacts/{id}/activate', [ContactController::class, 'activate'])->middleware('permission:contacts.edit');

    // Payment Terms
    Route::get('/payment-terms', [PaymentTermController::class, 'index'])->middleware('permission:payment_terms.view');
    Route::post('/payment-terms', [PaymentTermController::class, 'store'])->middleware('permission:payment_terms.create');
    Route::get('/payment-terms/{id}', [PaymentTermController::class, 'show'])->middleware('permission:payment_terms.view');
    Route::patch('/payment-terms/{id}', [PaymentTermController::class, 'update'])->middleware('permission:payment_terms.edit');
    Route::patch('/payment-terms/{id}/deactivate', [PaymentTermController::class, 'deactivate'])->middleware('permission:payment_terms.deactivate');
    Route::patch('/payment-terms/{id}/activate', [PaymentTermController::class, 'activate'])->middleware('permission:payment_terms.edit');

    // Units
    Route::get('/units', [UnitController::class, 'index'])->middleware('permission:units.view');
    Route::post('/units', [UnitController::class, 'store'])->middleware('permission:units.create');
    Route::get('/units/{id}', [UnitController::class, 'show'])->middleware('permission:units.view');
    Route::patch('/units/{id}', [UnitController::class, 'update'])->middleware('permission:units.edit');
    Route::patch('/units/{id}/deactivate', [UnitController::class, 'deactivate'])->middleware('permission:units.deactivate');
    Route::patch('/units/{id}/activate', [UnitController::class, 'activate'])->middleware('permission:units.edit');

    // Product Categories
    Route::get('/product-categories', [ProductCategoryController::class, 'index'])->middleware('permission:products.view');
    Route::post('/product-categories', [ProductCategoryController::class, 'store'])->middleware('permission:products.create');
    Route::get('/product-categories/{id}', [ProductCategoryController::class, 'show'])->middleware('permission:products.view');
    Route::patch('/product-categories/{id}', [ProductCategoryController::class, 'update'])->middleware('permission:products.edit');
    Route::patch('/product-categories/{id}/deactivate', [ProductCategoryController::class, 'deactivate'])->middleware('permission:products.deactivate');
    Route::patch('/product-categories/{id}/activate', [ProductCategoryController::class, 'activate'])->middleware('permission:products.edit');

    // Products
    Route::get('/products', [ProductController::class, 'index'])->middleware('permission:products.view');
    Route::post('/products', [ProductController::class, 'store'])->middleware('permission:products.create');
    Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('permission:products.view');
    Route::patch('/products/{id}', [ProductController::class, 'update'])->middleware('permission:products.edit');
    Route::patch('/products/{id}/deactivate', [ProductController::class, 'deactivate'])->middleware('permission:products.deactivate');
    Route::patch('/products/{id}/activate', [ProductController::class, 'activate'])->middleware('permission:products.edit');

    // Warehouses
    Route::get('/warehouses', [WarehouseController::class, 'index'])->middleware('permission:warehouses.view');
    Route::post('/warehouses', [WarehouseController::class, 'store'])->middleware('permission:warehouses.create');
    Route::get('/warehouses/{id}', [WarehouseController::class, 'show'])->middleware('permission:warehouses.view');
    Route::patch('/warehouses/{id}', [WarehouseController::class, 'update'])->middleware('permission:warehouses.edit');
    Route::patch('/warehouses/{id}/deactivate', [WarehouseController::class, 'deactivate'])->middleware('permission:warehouses.deactivate');
    Route::patch('/warehouses/{id}/activate', [WarehouseController::class, 'activate'])->middleware('permission:warehouses.edit');

    // Departments (Analytical Dimensions)
    Route::get('/departments', [DepartmentController::class, 'index'])->middleware('permission:departments.view');
    Route::post('/departments', [DepartmentController::class, 'store'])->middleware('permission:departments.create');
    Route::get('/departments/{id}', [DepartmentController::class, 'show'])->middleware('permission:departments.view');
    Route::patch('/departments/{id}', [DepartmentController::class, 'update'])->middleware('permission:departments.edit');
    Route::patch('/departments/{id}/deactivate', [DepartmentController::class, 'deactivate'])->middleware('permission:departments.deactivate');
    Route::patch('/departments/{id}/activate', [DepartmentController::class, 'activate'])->middleware('permission:departments.edit');

    // Projects (Analytical Dimensions)
    Route::get('/projects', [ProjectController::class, 'index'])->middleware('permission:projects.view');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('permission:projects.create');
    Route::get('/projects/{id}', [ProjectController::class, 'show'])->middleware('permission:projects.view');
    Route::patch('/projects/{id}', [ProjectController::class, 'update'])->middleware('permission:projects.edit');
    Route::patch('/projects/{id}/deactivate', [ProjectController::class, 'deactivate'])->middleware('permission:projects.deactivate');
    Route::patch('/projects/{id}/activate', [ProjectController::class, 'activate'])->middleware('permission:projects.edit');

    // Account Mappings
    Route::get('/account-mappings', [AccountMappingController::class, 'index'])->middleware('permission:settings.company.view');
    Route::patch('/account-mappings/{mappingKey}', [AccountMappingController::class, 'update'])->middleware('permission:settings.company.edit');
});

Route::middleware(['auth:sanctum', 'company.access'])->prefix('reports')->group(function () {
    Route::get('/general-ledger', [GeneralLedgerController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/account-ledger/{account}', [AccountLedgerDetailController::class, 'show'])->middleware('permission:reports.view');
    Route::get('/trial-balance', [TrialBalanceController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/profit-loss', [ProfitLossController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/balance-sheet', [BalanceSheetController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/cash-flow', [CashFlowController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/financial-summary', [FinancialSummaryController::class, 'index'])->middleware('permission:reports.view');
});

Route::middleware(['auth:sanctum', 'company.access'])->prefix('sales')->group(function () {
    Route::get('/source-documents/availability', [SourceDocumentPickerController::class, 'availability'])->middleware('permission:sales.orders.view');
    Route::get('/source-documents', [SourceDocumentPickerController::class, 'index'])->middleware('permission:sales.orders.view');

    Route::get('/ar/customer-summary', [AccountsReceivableController::class, 'customerSummary'])->middleware('permission:sales.ar.view');
    Route::get('/ar/customers/{customerId}/ledger', [AccountsReceivableController::class, 'customerLedger'])->middleware('permission:sales.ar.view');
    Route::get('/ar/invoices/{invoiceId}/ledger', [AccountsReceivableController::class, 'invoiceLedger'])->middleware('permission:sales.ar.view');
    Route::get('/ar/open-invoices', [AccountsReceivableController::class, 'openInvoices'])->middleware('permission:sales.ar.view');
    Route::get('/ar/aging', [AccountsReceivableController::class, 'aging'])->middleware('permission:sales.ar.view');
    Route::get('/ar/reconciliation', [AccountsReceivableController::class, 'reconciliation'])->middleware('permission:sales.ar.reconcile');

    Route::get('/quotations', [SalesQuotationController::class, 'index'])->middleware('permission:sales.quotations.view');
    Route::post('/quotations', [SalesQuotationController::class, 'store'])->middleware('permission:sales.quotations.create');
    Route::get('/quotations/{id}', [SalesQuotationController::class, 'show'])->middleware('permission:sales.quotations.view');
    Route::patch('/quotations/{id}', [SalesQuotationController::class, 'update'])->middleware('permission:sales.quotations.edit');
    Route::patch('/quotations/{id}/send', [SalesQuotationController::class, 'send'])->middleware('permission:sales.quotations.edit');
    Route::patch('/quotations/{id}/approve', [SalesQuotationController::class, 'approve'])->middleware('permission:sales.quotations.approve');
    Route::patch('/quotations/{id}/accept', [SalesQuotationController::class, 'accept'])->middleware('permission:sales.quotations.approve');
    Route::patch('/quotations/{id}/reject', [SalesQuotationController::class, 'reject'])->middleware('permission:sales.quotations.cancel');
    Route::patch('/quotations/{id}/cancel', [SalesQuotationController::class, 'cancel'])->middleware('permission:sales.quotations.cancel');

    Route::get('/orders', [SalesOrderController::class, 'index'])->middleware('permission:sales.orders.view');
    Route::post('/orders', [SalesOrderController::class, 'store'])->middleware('permission:sales.orders.create');
    Route::get('/orders/{id}', [SalesOrderController::class, 'show'])->middleware('permission:sales.orders.view');
    Route::patch('/orders/{id}', [SalesOrderController::class, 'update'])->middleware('permission:sales.orders.edit');
    Route::post('/orders/from-quotation/{quotationId}', [SalesOrderController::class, 'createFromQuotation'])->middleware('permission:sales.orders.convert');
    Route::patch('/orders/{id}/approve', [SalesOrderController::class, 'approve'])->middleware('permission:sales.orders.approve');
    Route::patch('/orders/{id}/confirm', [SalesOrderController::class, 'confirm'])->middleware('permission:sales.orders.confirm');
    Route::patch('/orders/{id}/cancel', [SalesOrderController::class, 'cancel'])->middleware('permission:sales.orders.cancel');
    Route::patch('/orders/{id}/close', [SalesOrderController::class, 'close'])->middleware('permission:sales.orders.confirm');

    Route::get('/delivery-orders', [DeliveryOrderController::class, 'index'])->middleware('permission:sales.delivery_orders.view');
    Route::post('/delivery-orders', [DeliveryOrderController::class, 'store'])->middleware('permission:sales.delivery_orders.create');
    Route::get('/delivery-orders/{id}', [DeliveryOrderController::class, 'show'])->middleware('permission:sales.delivery_orders.view');
    Route::patch('/delivery-orders/{id}', [DeliveryOrderController::class, 'update'])->middleware('permission:sales.delivery_orders.edit');
    Route::post('/delivery-orders/from-sales-order/{salesOrderId}', [DeliveryOrderController::class, 'createFromSalesOrder'])->middleware('permission:sales.delivery_orders.create');
    Route::patch('/delivery-orders/{id}/ready', [DeliveryOrderController::class, 'ready'])->middleware('permission:sales.delivery_orders.ship');
    Route::patch('/delivery-orders/{id}/ship', [DeliveryOrderController::class, 'ship'])->middleware('permission:sales.delivery_orders.ship');
    Route::patch('/delivery-orders/{id}/deliver', [DeliveryOrderController::class, 'deliver'])->middleware('permission:sales.delivery_orders.deliver');
    Route::patch('/delivery-orders/{id}/cancel', [DeliveryOrderController::class, 'cancel'])->middleware('permission:sales.delivery_orders.cancel');
    Route::patch('/delivery-orders/{id}/void', [DeliveryOrderController::class, 'void'])->middleware('permission:sales.delivery_orders.void');

    Route::get('/proformas', [ProformaInvoiceController::class, 'index'])->middleware('permission:sales.proformas.view');
    Route::post('/proformas', [ProformaInvoiceController::class, 'store'])->middleware('permission:sales.proformas.create');
    Route::get('/proformas/{id}', [ProformaInvoiceController::class, 'show'])->middleware('permission:sales.proformas.view');
    Route::patch('/proformas/{id}', [ProformaInvoiceController::class, 'update'])->middleware('permission:sales.proformas.edit');
    Route::post('/proformas/from-sales-order/{salesOrderId}', [ProformaInvoiceController::class, 'createFromSalesOrder'])->middleware('permission:sales.proformas.convert');
    Route::patch('/proformas/{id}/issue', [ProformaInvoiceController::class, 'issue'])->middleware('permission:sales.proformas.issue');
    Route::patch('/proformas/{id}/accept', [ProformaInvoiceController::class, 'accept'])->middleware('permission:sales.proformas.issue');
    Route::patch('/proformas/{id}/cancel', [ProformaInvoiceController::class, 'cancel'])->middleware('permission:sales.proformas.cancel');

    Route::get('/invoices', [SalesInvoiceController::class, 'index'])->middleware('permission:sales.invoices.view');
    Route::post('/invoices', [SalesInvoiceController::class, 'store'])->middleware('permission:sales.invoices.create');
    Route::get('/invoices/{id}', [SalesInvoiceController::class, 'show'])->middleware('permission:sales.invoices.view');
    Route::patch('/invoices/{id}', [SalesInvoiceController::class, 'update'])->middleware('permission:sales.invoices.edit');
    Route::post('/invoices/from-sales-order/{salesOrderId}', [SalesInvoiceController::class, 'createFromSalesOrder'])->middleware('permission:sales.invoices.create');
    Route::post('/invoices/from-delivery-order/{deliveryOrderId}', [SalesInvoiceController::class, 'createFromDeliveryOrder'])->middleware('permission:sales.invoices.create');
    Route::post('/invoices/from-proforma/{proformaId}', [SalesInvoiceController::class, 'createFromProforma'])->middleware('permission:sales.invoices.create');
    Route::patch('/invoices/{id}/approve', [SalesInvoiceController::class, 'approve'])->middleware('permission:sales.invoices.approve');
    Route::patch('/invoices/{id}/post', [SalesInvoiceController::class, 'post'])->middleware('permission:sales.invoices.post');
    Route::patch('/invoices/{id}/void', [SalesInvoiceController::class, 'void'])->middleware('permission:sales.invoices.void');

    Route::get('/billings', [BillingInvoiceController::class, 'index'])->middleware('permission:sales.billings.view');
    Route::post('/billings', [BillingInvoiceController::class, 'store'])->middleware('permission:sales.billings.create');
    Route::get('/billings/{id}', [BillingInvoiceController::class, 'show'])->middleware('permission:sales.billings.view');
    Route::post('/billings/from-sales-invoice/{salesInvoiceId}', [BillingInvoiceController::class, 'createFromSalesInvoice'])->middleware('permission:sales.billings.create');
    Route::patch('/billings/{id}/issue', [BillingInvoiceController::class, 'issue'])->middleware('permission:sales.billings.issue');
    Route::patch('/billings/{id}/cancel', [BillingInvoiceController::class, 'cancel'])->middleware('permission:sales.billings.cancel');

    Route::get('/customer-deposits', [CustomerDepositController::class, 'index'])->middleware('permission:sales.deposits.view');
    Route::post('/customer-deposits', [CustomerDepositController::class, 'store'])->middleware('permission:sales.deposits.create');
    Route::get('/customer-deposits/{id}', [CustomerDepositController::class, 'show'])->middleware('permission:sales.deposits.view');
    Route::patch('/customer-deposits/{id}/post', [CustomerDepositController::class, 'post'])->middleware('permission:sales.deposits.post');
    Route::patch('/customer-deposits/{id}/void', [CustomerDepositController::class, 'void'])->middleware('permission:sales.deposits.void');
    Route::patch('/customer-deposits/{id}/refund', [CustomerDepositController::class, 'refund'])->middleware('permission:sales.deposits.refund');
    Route::post('/customer-deposits/{id}/allocate-to-invoice/{invoiceId}', [CustomerDepositController::class, 'allocateToInvoice'])->middleware('permission:sales.deposits.post');

    Route::get('/receipts', [SalesReceiptController::class, 'index'])->middleware('permission:sales.receipts.view');
    Route::post('/receipts', [SalesReceiptController::class, 'store'])->middleware('permission:sales.receipts.create');
    Route::get('/receipts/{id}', [SalesReceiptController::class, 'show'])->middleware('permission:sales.receipts.view');
    Route::patch('/receipts/{id}/post', [SalesReceiptController::class, 'post'])->middleware('permission:sales.receipts.post');
    Route::patch('/receipts/{id}/void', [SalesReceiptController::class, 'void'])->middleware('permission:sales.receipts.void');

    Route::get('/returns', [SalesReturnController::class, 'index'])->middleware('permission:sales.returns.view');
    Route::post('/returns', [SalesReturnController::class, 'store'])->middleware('permission:sales.returns.create');
    Route::get('/returns/{id}', [SalesReturnController::class, 'show'])->middleware('permission:sales.returns.view');
    Route::patch('/returns/{id}', [SalesReturnController::class, 'update'])->middleware('permission:sales.returns.create');
    Route::post('/returns/from-invoice/{invoiceId}', [SalesReturnController::class, 'createFromSalesInvoice'])->middleware('permission:sales.returns.create');
    Route::post('/returns/from-delivery-order/{deliveryOrderId}', [SalesReturnController::class, 'createFromDeliveryOrder'])->middleware('permission:sales.returns.create');
    Route::patch('/returns/{id}/approve', [SalesReturnController::class, 'approve'])->middleware('permission:sales.returns.approve');
    Route::patch('/returns/{id}/post', [SalesReturnController::class, 'post'])->middleware('permission:sales.returns.post');
    Route::patch('/returns/{id}/void', [SalesReturnController::class, 'void'])->middleware('permission:sales.returns.void');
});

Route::middleware(['auth:sanctum', 'company.access'])->prefix('purchase')->group(function () {
    Route::get('/source-documents/availability', [SourceDocumentPickerController::class, 'availability'])->middleware('permission:purchase.orders.view');
    Route::get('/source-documents', [SourceDocumentPickerController::class, 'index'])->middleware('permission:purchase.orders.view');

    Route::get('/ap/vendor-summary', [AccountsPayableController::class, 'vendorSummary'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/vendors/{vendorId}/ledger', [AccountsPayableController::class, 'vendorLedger'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/bills/{billId}/ledger', [AccountsPayableController::class, 'billLedger'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/open-bills', [AccountsPayableController::class, 'openBills'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/aging', [AccountsPayableController::class, 'aging'])->middleware('permission:purchase.ap.view');
    Route::get('/ap/reconciliation', [AccountsPayableController::class, 'reconciliation'])->middleware('permission:purchase.ap.reconcile');

    Route::get('/requests', [PurchaseRequestController::class, 'index'])->middleware('permission:purchase.requests.view');
    Route::post('/requests', [PurchaseRequestController::class, 'store'])->middleware('permission:purchase.requests.create');
    Route::get('/requests/{id}', [PurchaseRequestController::class, 'show'])->middleware('permission:purchase.requests.view');
    Route::patch('/requests/{id}', [PurchaseRequestController::class, 'update'])->middleware('permission:purchase.requests.edit');
    Route::patch('/requests/{id}/submit', [PurchaseRequestController::class, 'submit'])->middleware('permission:purchase.requests.edit');
    Route::patch('/requests/{id}/approve', [PurchaseRequestController::class, 'approve'])->middleware('permission:purchase.requests.approve');
    Route::patch('/requests/{id}/reject', [PurchaseRequestController::class, 'reject'])->middleware('permission:purchase.requests.cancel');
    Route::patch('/requests/{id}/cancel', [PurchaseRequestController::class, 'cancel'])->middleware('permission:purchase.requests.cancel');

    Route::get('/orders', [PurchaseOrderController::class, 'index'])->middleware('permission:purchase.orders.view');
    Route::post('/orders', [PurchaseOrderController::class, 'store'])->middleware('permission:purchase.orders.create');
    Route::get('/orders/{id}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchase.orders.view');
    Route::patch('/orders/{id}', [PurchaseOrderController::class, 'update'])->middleware('permission:purchase.orders.edit');
    Route::post('/orders/from-request/{purchaseRequestId}', [PurchaseOrderController::class, 'createFromPurchaseRequest'])->middleware('permission:purchase.orders.convert');
    Route::patch('/orders/{id}/approve', [PurchaseOrderController::class, 'approve'])->middleware('permission:purchase.orders.approve');
    Route::patch('/orders/{id}/confirm', [PurchaseOrderController::class, 'confirm'])->middleware('permission:purchase.orders.confirm');
    Route::patch('/orders/{id}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware('permission:purchase.orders.cancel');
    Route::patch('/orders/{id}/close', [PurchaseOrderController::class, 'close'])->middleware('permission:purchase.orders.confirm');

    Route::get('/goods-receipts', [GoodsReceiptController::class, 'index'])->middleware('permission:purchase.goods_receipts.view');
    Route::post('/goods-receipts', [GoodsReceiptController::class, 'store'])->middleware('permission:purchase.goods_receipts.create');
    Route::get('/goods-receipts/{id}', [GoodsReceiptController::class, 'show'])->middleware('permission:purchase.goods_receipts.view');
    Route::patch('/goods-receipts/{id}', [GoodsReceiptController::class, 'update'])->middleware('permission:purchase.goods_receipts.edit');
    Route::post('/goods-receipts/from-purchase-order/{purchaseOrderId}', [GoodsReceiptController::class, 'createFromPurchaseOrder'])->middleware('permission:purchase.goods_receipts.create');
    Route::patch('/goods-receipts/{id}/receive', [GoodsReceiptController::class, 'receive'])->middleware('permission:purchase.goods_receipts.receive');
    Route::patch('/goods-receipts/{id}/cancel', [GoodsReceiptController::class, 'cancel'])->middleware('permission:purchase.goods_receipts.cancel');
    Route::patch('/goods-receipts/{id}/void', [GoodsReceiptController::class, 'void'])->middleware('permission:purchase.goods_receipts.void');

    Route::get('/bills', [VendorBillController::class, 'index'])->middleware('permission:purchase.bills.view');
    Route::post('/bills', [VendorBillController::class, 'store'])->middleware('permission:purchase.bills.create');
    Route::get('/bills/{id}', [VendorBillController::class, 'show'])->middleware('permission:purchase.bills.view');
    Route::patch('/bills/{id}', [VendorBillController::class, 'update'])->middleware('permission:purchase.bills.edit');
    Route::post('/bills/from-purchase-order/{purchaseOrderId}', [VendorBillController::class, 'createFromPurchaseOrder'])->middleware('permission:purchase.bills.create');
    Route::post('/bills/from-goods-receipt/{goodsReceiptId}', [VendorBillController::class, 'createFromGoodsReceipt'])->middleware('permission:purchase.bills.create');
    Route::patch('/bills/{id}/approve', [VendorBillController::class, 'approve'])->middleware('permission:purchase.bills.approve');
    Route::patch('/bills/{id}/post', [VendorBillController::class, 'post'])->middleware('permission:purchase.bills.post');
    Route::patch('/bills/{id}/void', [VendorBillController::class, 'void'])->middleware('permission:purchase.bills.void');

    Route::get('/vendor-deposits', [VendorDepositController::class, 'index'])->middleware('permission:purchase.deposits.view');
    Route::post('/vendor-deposits', [VendorDepositController::class, 'store'])->middleware('permission:purchase.deposits.create');
    Route::get('/vendor-deposits/{id}', [VendorDepositController::class, 'show'])->middleware('permission:purchase.deposits.view');
    Route::patch('/vendor-deposits/{id}/post', [VendorDepositController::class, 'post'])->middleware('permission:purchase.deposits.post');
    Route::patch('/vendor-deposits/{id}/void', [VendorDepositController::class, 'void'])->middleware('permission:purchase.deposits.void');
    Route::patch('/vendor-deposits/{id}/refund', [VendorDepositController::class, 'refund'])->middleware('permission:purchase.deposits.refund');
    Route::post('/vendor-deposits/{id}/allocate-to-bill/{billId}', [VendorDepositController::class, 'allocateToBill'])->middleware('permission:purchase.deposits.post');

    Route::get('/payments', [VendorPaymentController::class, 'index'])->middleware('permission:purchase.payments.view');
    Route::post('/payments', [VendorPaymentController::class, 'store'])->middleware('permission:purchase.payments.create');
    Route::get('/payments/{id}', [VendorPaymentController::class, 'show'])->middleware('permission:purchase.payments.view');
    Route::patch('/payments/{id}/post', [VendorPaymentController::class, 'post'])->middleware('permission:purchase.payments.post');
    Route::patch('/payments/{id}/void', [VendorPaymentController::class, 'void'])->middleware('permission:purchase.payments.void');

    Route::get('/returns', [PurchaseReturnController::class, 'index'])->middleware('permission:purchase.returns.view');
    Route::post('/returns', [PurchaseReturnController::class, 'store'])->middleware('permission:purchase.returns.create');
    Route::get('/returns/{id}', [PurchaseReturnController::class, 'show'])->middleware('permission:purchase.returns.view');
    Route::patch('/returns/{id}', [PurchaseReturnController::class, 'update'])->middleware('permission:purchase.returns.create');
    Route::post('/returns/from-bill/{billId}', [PurchaseReturnController::class, 'createFromVendorBill'])->middleware('permission:purchase.returns.create');
    Route::post('/returns/from-goods-receipt/{goodsReceiptId}', [PurchaseReturnController::class, 'createFromGoodsReceipt'])->middleware('permission:purchase.returns.create');
    Route::patch('/returns/{id}/approve', [PurchaseReturnController::class, 'approve'])->middleware('permission:purchase.returns.approve');
    Route::patch('/returns/{id}/post', [PurchaseReturnController::class, 'post'])->middleware('permission:purchase.returns.post');
    Route::patch('/returns/{id}/void', [PurchaseReturnController::class, 'void'])->middleware('permission:purchase.returns.void');
});

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    // Phase 6: Journal Entry Engine (manual journals only). No DELETE routes.
    Route::get('/journals', [JournalEntryController::class, 'index'])->middleware('permission:journal.view');
    Route::post('/journals', [JournalEntryController::class, 'store'])->middleware('permission:journal.create');
    Route::get('/journals/{id}', [JournalEntryController::class, 'show'])->middleware('permission:journal.view');
    Route::patch('/journals/{id}', [JournalEntryController::class, 'update'])->middleware('permission:journal.edit');
    Route::post('/journals/{id}/approve', [JournalEntryController::class, 'approve'])->middleware('permission:journal.approve');
    Route::post('/journals/{id}/post', [JournalEntryController::class, 'post'])->middleware('permission:journal.post');
    Route::post('/journals/{id}/void', [JournalEntryController::class, 'void'])->middleware('permission:journal.void');
});
