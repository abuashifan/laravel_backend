<?php

use App\Http\Controllers\Api\Reports\AccountLedgerDetailController;
use App\Http\Controllers\Api\Reports\BalanceSheetController;
use App\Http\Controllers\Api\Reports\CashFlowController;
use App\Http\Controllers\Api\Reports\FinancialSummaryController;
use App\Http\Controllers\Api\Reports\GeneralLedgerController;
use App\Http\Controllers\Api\Reports\ProfitLossController;
use App\Http\Controllers\Api\Reports\ReconciliationReportController;
use App\Http\Controllers\Api\Reports\TrialBalanceController;
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
});
