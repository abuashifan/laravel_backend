<?php

use App\Modules\Budget\Controllers\BudgetAnalysisController;
use App\Modules\Budget\Controllers\BudgetCashController;
use App\Modules\Budget\Controllers\BudgetConsolidationController;
use App\Modules\Budget\Controllers\BudgetPeriodController;
use App\Modules\Budget\Controllers\BudgetProjectController;
use App\Modules\Budget\Controllers\BudgetSubmissionController;
use App\Modules\Reports\Controllers\BudgetComparisonController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {

    Route::prefix('budget-periods')->group(function () {
        Route::get('/', [BudgetPeriodController::class, 'index'])->middleware('permission:budgets.view');
        Route::post('/', [BudgetPeriodController::class, 'store'])->middleware('permission:budgets.manage');
        Route::get('/{id}', [BudgetPeriodController::class, 'show'])->middleware('permission:budgets.view');
        Route::put('/{id}', [BudgetPeriodController::class, 'update'])->middleware('permission:budgets.manage');
        Route::post('/{id}/close', [BudgetPeriodController::class, 'close'])->middleware('permission:budgets.manage');
        Route::get('/{id}/submissions', [BudgetSubmissionController::class, 'index'])->middleware('permission:budgets.view');
        Route::post('/{id}/submissions', [BudgetSubmissionController::class, 'store'])->middleware('permission:budgets.submit');
        Route::get('/{id}/consolidation', [BudgetConsolidationController::class, 'show'])->middleware('permission:budgets.view');
    });

    Route::prefix('budget-submissions')->group(function () {
        // Terdaftar sebelum `/{id}` supaya tidak tertelan sebagai id bernilai kosong.
        Route::get('/', [BudgetSubmissionController::class, 'indexAll'])->middleware('permission:budgets.view');
        Route::get('/{id}', [BudgetSubmissionController::class, 'show'])->middleware('permission:budgets.view');
        Route::put('/{id}', [BudgetSubmissionController::class, 'update'])->middleware('permission:budgets.submit');
        Route::put('/{id}/lines', [BudgetSubmissionController::class, 'updateLines'])->middleware('permission:budgets.submit');
        Route::post('/{id}/submit', [BudgetSubmissionController::class, 'submit'])->middleware('permission:budgets.submit');
        Route::post('/{id}/approve-head', [BudgetSubmissionController::class, 'approveHead'])->middleware('permission:budgets.approve_head');
        Route::post('/{id}/approve-finance', [BudgetSubmissionController::class, 'approveFinance'])->middleware('permission:budgets.approve_finance');
        // Penolakan bisa terjadi di dua tahap: oleh kepala (status `submitted`)
        // dan oleh finance (status `approved_by_head`). Sintaks `|` berarti
        // salah satu permission cukup — tanpa itu role yang hanya memegang
        // budgets.approve_finance tidak bisa menolak di tahapnya sendiri.
        Route::post('/{id}/reject', [BudgetSubmissionController::class, 'reject'])->middleware('permission:budgets.approve_head|budgets.approve_finance');
        Route::get('/{id}/versions', [BudgetSubmissionController::class, 'versions'])->middleware('permission:budgets.view');
        Route::post('/{id}/revise', [BudgetSubmissionController::class, 'revise'])->middleware('permission:budgets.revise');
    });

    // Mesin analisis. Semua rute di bawah ini preset di atas
    // `BudgetAnalysisService` — nol logika agregasi di controller.
    Route::prefix('budget')->group(function () {
        Route::get('/analysis', [BudgetAnalysisController::class, 'analysis'])->middleware('permission:budgets.view');
        Route::get('/summary', [BudgetAnalysisController::class, 'summary'])->middleware('permission:budgets.view');
        Route::get('/cash', [BudgetCashController::class, 'show'])->middleware('permission:budgets.view');
        Route::get('/projects/{id}/summary', [BudgetProjectController::class, 'summary'])->middleware('permission:budgets.view');
        Route::get('/projects/{id}/profitability', [BudgetProjectController::class, 'profitability'])->middleware('permission:budgets.view');
        Route::get('/projects/{id}/cash-flow', [BudgetProjectController::class, 'cashFlow'])->middleware('permission:budgets.view');
        Route::get('/projects/{id}/transactions', [BudgetProjectController::class, 'transactions'])->middleware('permission:budgets.view');
    });

    Route::prefix('reports/budget')->group(function () {
        Route::get('/by-account', [BudgetAnalysisController::class, 'byAccount'])->middleware('permission:budgets.view');
        Route::get('/by-cost-center', [BudgetAnalysisController::class, 'byCostCenter'])->middleware('permission:budgets.view');
        Route::get('/by-project', [BudgetAnalysisController::class, 'byProject'])->middleware('permission:budgets.view');
        Route::get('/by-period', [BudgetAnalysisController::class, 'byPeriod'])->middleware('permission:budgets.view');
        Route::get('/utilization', [BudgetAnalysisController::class, 'utilization'])->middleware('permission:budgets.view');
        Route::get('/variance', [BudgetAnalysisController::class, 'variance'])->middleware('permission:budgets.view');
        // Dipertahankan sebagai alias tipis ke by-account&mode=variance supaya
        // `BudgetComparisonPage` yang sudah jalan tidak rusak.
        Route::get('/comparison', [BudgetComparisonController::class, 'show'])->middleware('permission:budgets.view');
    });

});
