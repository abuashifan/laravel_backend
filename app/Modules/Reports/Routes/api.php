<?php

use App\Modules\Reports\Controllers\AccountLedgerDetailController;
use App\Modules\Reports\Controllers\BalanceSheetController;
use App\Modules\Reports\Controllers\CashFlowController;
use App\Modules\Reports\Controllers\FinancialSummaryController;
use App\Modules\Reports\Controllers\GeneralLedgerController;
use App\Modules\Reports\Controllers\ProfitLossController;
use App\Modules\Reports\Controllers\Purchase\PurchaseReportController;
use App\Modules\Reports\Controllers\ReconciliationReportController;
use App\Modules\Reports\Controllers\Sales\SalesReportController;
use App\Modules\Reports\Controllers\TrialBalanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('reports')->group(function () {
    Route::get('/general-ledger', [GeneralLedgerController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/account-ledger/{account}', [AccountLedgerDetailController::class, 'show'])->middleware('permission:reports.view');
    Route::get('/trial-balance', [TrialBalanceController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/profit-loss', [ProfitLossController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/balance-sheet', [BalanceSheetController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/cash-flow', [CashFlowController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/financial-summary', [FinancialSummaryController::class, 'index'])->middleware('permission:reports.view');
    Route::get('/reconciliation/ar', [ReconciliationReportController::class, 'ar'])->middleware('permission:reports.view');
    Route::get('/reconciliation/ap', [ReconciliationReportController::class, 'ap'])->middleware('permission:reports.view');
    Route::get('/reconciliation/inventory', [ReconciliationReportController::class, 'inventory'])->middleware('permission:reports.view');
    Route::get('/reconciliation/grni', [ReconciliationReportController::class, 'grni'])->middleware('permission:reports.view');
    Route::get('/reconciliation/customer-deposits', [ReconciliationReportController::class, 'customerDeposits'])->middleware('permission:reports.view');
    Route::get('/reconciliation/vendor-deposits', [ReconciliationReportController::class, 'vendorDeposits'])->middleware('permission:reports.view');

    Route::prefix('sales')->middleware('permission:reports.view')->group(function () {
        Route::get('/summary', [SalesReportController::class, 'summary']);
        Route::get('/by-customer', [SalesReportController::class, 'byCustomer']);
        Route::get('/by-product', [SalesReportController::class, 'byProduct']);
    });

    Route::prefix('purchase')->middleware('permission:reports.view')->group(function () {
        Route::get('/summary', [PurchaseReportController::class, 'summary']);
        Route::get('/by-vendor', [PurchaseReportController::class, 'byVendor']);
        Route::get('/by-product', [PurchaseReportController::class, 'byProduct']);
    });
});
