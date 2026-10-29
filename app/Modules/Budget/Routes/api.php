<?php

use App\Modules\Budget\Controllers\BudgetAllocationController;
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
        Route::get('/', [BudgetPeriodController::class, 'index'])->middleware('permission:budget.submissions.view');
        Route::post('/', [BudgetPeriodController::class, 'store'])->middleware('permission:budget.periods.manage');
        Route::get('/{id}', [BudgetPeriodController::class, 'show'])->middleware('permission:budget.submissions.view');
        Route::put('/{id}', [BudgetPeriodController::class, 'update'])->middleware('permission:budget.periods.manage');
        Route::post('/{id}/close', [BudgetPeriodController::class, 'close'])->middleware('permission:budget.periods.manage');
        Route::get('/{id}/submissions', [BudgetSubmissionController::class, 'index'])->middleware('permission:budget.submissions.view');
        Route::post('/{id}/submissions', [BudgetSubmissionController::class, 'store'])->middleware('permission:budget.submissions.create');
        Route::get('/{id}/consolidation', [BudgetConsolidationController::class, 'show'])->middleware('permission:budget.submissions.view');
        // Gap A — pagu top-down. Wewenang yang sama dengan membuat/menutup
        // periode: menetapkan plafon adalah keputusan Finance, bukan pengaju.
        Route::get('/{id}/allocations', [BudgetAllocationController::class, 'index'])->middleware('permission:budget.submissions.view');
        Route::post('/{id}/allocations', [BudgetAllocationController::class, 'store'])->middleware('permission:budget.periods.manage');
    });

    Route::prefix('budget-allocations')->group(function () {
        Route::get('/{id}', [BudgetAllocationController::class, 'show'])->middleware('permission:budget.submissions.view');
        Route::put('/{id}', [BudgetAllocationController::class, 'update'])->middleware('permission:budget.periods.manage');
    });

    Route::prefix('budget-submissions')->group(function () {
        // Terdaftar sebelum `/{id}` supaya tidak tertelan sebagai id bernilai kosong.
        Route::get('/', [BudgetSubmissionController::class, 'indexAll'])->middleware('permission:budget.submissions.view');
        Route::get('/{id}', [BudgetSubmissionController::class, 'show'])->middleware('permission:budget.submissions.view');
        Route::put('/{id}', [BudgetSubmissionController::class, 'update'])->middleware('permission:budget.submissions.create');
        Route::put('/{id}/lines', [BudgetSubmissionController::class, 'updateLines'])->middleware('permission:budget.submissions.create');
        Route::post('/{id}/submit', [BudgetSubmissionController::class, 'submit'])->middleware('permission:budget.submissions.create');
        Route::post('/{id}/approve-head', [BudgetSubmissionController::class, 'approveHead'])->middleware('permission:budget.submissions.approve_head');
        Route::post('/{id}/approve-finance', [BudgetSubmissionController::class, 'approveFinance'])->middleware('permission:budget.submissions.approve_finance');
        // Penolakan bisa terjadi di dua tahap: oleh kepala (status `submitted`)
        // dan oleh finance (status `approved_by_head`). Sintaks `|` berarti
        // salah satu permission cukup — tanpa itu role yang hanya memegang
        // budgets.approve_finance tidak bisa menolak di tahapnya sendiri.
        Route::post('/{id}/reject', [BudgetSubmissionController::class, 'reject'])->middleware('permission:budget.submissions.approve_head|budget.submissions.approve_finance');
        Route::get('/{id}/versions', [BudgetSubmissionController::class, 'versions'])->middleware('permission:budget.submissions.view');
        Route::post('/{id}/revise', [BudgetSubmissionController::class, 'revise'])->middleware('permission:budget.submissions.edit');
    });

    // Mesin analisis. Semua rute di bawah ini preset di atas
    // `BudgetAnalysisService` — nol logika agregasi di controller.
    Route::prefix('budget')->group(function () {
        Route::get('/analysis', [BudgetAnalysisController::class, 'analysis'])->middleware('permission:budget.submissions.view');
        Route::get('/summary', [BudgetAnalysisController::class, 'summary'])->middleware('permission:budget.submissions.view');
        Route::get('/cash', [BudgetCashController::class, 'show'])->middleware('permission:budget.submissions.view');
        Route::get('/projects/{id}/summary', [BudgetProjectController::class, 'summary'])->middleware('permission:budget.submissions.view');
        Route::get('/projects/{id}/profitability', [BudgetProjectController::class, 'profitability'])->middleware('permission:budget.submissions.view');
        Route::get('/projects/{id}/cash-flow', [BudgetProjectController::class, 'cashFlow'])->middleware('permission:budget.submissions.view');
        Route::get('/projects/{id}/transactions', [BudgetProjectController::class, 'transactions'])->middleware('permission:budget.submissions.view');
    });

    Route::prefix('reports/budget')->group(function () {
        Route::get('/by-account', [BudgetAnalysisController::class, 'byAccount'])->middleware('permission:budget.submissions.view');
        Route::get('/by-cost-center', [BudgetAnalysisController::class, 'byCostCenter'])->middleware('permission:budget.submissions.view');
        Route::get('/by-project', [BudgetAnalysisController::class, 'byProject'])->middleware('permission:budget.submissions.view');
        Route::get('/by-period', [BudgetAnalysisController::class, 'byPeriod'])->middleware('permission:budget.submissions.view');
        Route::get('/utilization', [BudgetAnalysisController::class, 'utilization'])->middleware('permission:budget.submissions.view');
        Route::get('/variance', [BudgetAnalysisController::class, 'variance'])->middleware('permission:budget.submissions.view');
        // Dipertahankan sebagai alias tipis ke by-account&mode=variance supaya
        // `BudgetComparisonPage` yang sudah jalan tidak rusak.
        Route::get('/comparison', [BudgetComparisonController::class, 'show'])->middleware('permission:budget.submissions.view');
    });

});
