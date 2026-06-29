<?php

use App\Http\Controllers\Api\Budget\BudgetConsolidationController;
use App\Http\Controllers\Api\Budget\BudgetPeriodController;
use App\Http\Controllers\Api\Budget\BudgetSubmissionController;
use App\Http\Controllers\Api\Reports\BudgetComparisonController;
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
        Route::get('/{id}', [BudgetSubmissionController::class, 'show'])->middleware('permission:budgets.view');
        Route::put('/{id}', [BudgetSubmissionController::class, 'update'])->middleware('permission:budgets.submit');
        Route::put('/{id}/lines', [BudgetSubmissionController::class, 'updateLines'])->middleware('permission:budgets.submit');
        Route::post('/{id}/submit', [BudgetSubmissionController::class, 'submit'])->middleware('permission:budgets.submit');
        Route::post('/{id}/approve-head', [BudgetSubmissionController::class, 'approveHead'])->middleware('permission:budgets.approve_head');
        Route::post('/{id}/approve-finance', [BudgetSubmissionController::class, 'approveFinance'])->middleware('permission:budgets.approve_finance');
        Route::post('/{id}/reject', [BudgetSubmissionController::class, 'reject'])->middleware('permission:budgets.approve_head');
    });

    Route::get('/reports/budget/comparison', [BudgetComparisonController::class, 'show'])->middleware('permission:budgets.view');

});
