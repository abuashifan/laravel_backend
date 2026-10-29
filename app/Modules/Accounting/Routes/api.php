<?php

use App\Modules\Accounting\Controllers\AccountMappingHealthController;
use App\Modules\Accounting\Controllers\DocumentHistoryController;
use App\Modules\Accounting\Controllers\FiscalYearClosingController;
use App\Modules\Accounting\Controllers\FiscalYearStatusController;
use App\Modules\Accounting\Controllers\PeriodEndController;
use App\Modules\Accounting\Controllers\PeriodLockController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access', 'permission:dashboard.overview.view'])->group(function () {
    Route::get('/accounting/fiscal-year/status', FiscalYearStatusController::class);
});

Route::middleware(['auth:sanctum', 'company.access'])->prefix('accounting')->group(function () {
    Route::get('/document-history/{type}/{id}', [DocumentHistoryController::class, 'show'])
        ->where(['type' => '[a-z_]+', 'id' => '[0-9]+'])
        ->middleware('permission:audit.document_history.view');

    Route::get('/fiscal-years/{id}/closing-preview', [FiscalYearClosingController::class, 'preview'])
        ->middleware('permission:accounting.fiscal_year.view');
    Route::get('/fiscal-years/{id}/closing-checklist', [FiscalYearClosingController::class, 'checklist'])
        ->middleware('permission:accounting.fiscal_year.approve');
    Route::post('/fiscal-years/{id}/close', [FiscalYearClosingController::class, 'close'])
        ->middleware('permission:accounting.fiscal_year.approve');
    Route::post('/fiscal-years/{id}/reopen', [FiscalYearClosingController::class, 'reopen'])
        ->middleware('permission:accounting.fiscal_year.reopen');

    Route::get('/period-locks/status', [PeriodLockController::class, 'status'])
        ->middleware('permission:accounting.period_locks.view');
    Route::patch('/period-locks', [PeriodLockController::class, 'update'])
        ->middleware('permission:accounting.period_locks.edit');

    Route::get('/period-end/status', [PeriodEndController::class, 'status'])
        ->middleware('permission:accounting.period_end.view');
    Route::get('/period-end/checklist', [PeriodEndController::class, 'checklist'])
        ->middleware('permission:accounting.period_end.view');
    Route::post('/period-end/run', [PeriodEndController::class, 'run'])
        ->middleware('permission:accounting.period_end.approve');
    Route::post('/period-end/reopen', [PeriodEndController::class, 'reopen'])
        ->middleware('permission:accounting.period_end.reopen');
});

Route::middleware(['auth:sanctum', 'company.access'])
    ->prefix('v1/accounting')
    ->group(function () {
        Route::get('/account-mapping-health', [AccountMappingHealthController::class, 'index'])
            ->middleware('permission:accounting.fiscal_year.view');
    });
