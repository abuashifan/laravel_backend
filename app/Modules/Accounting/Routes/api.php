<?php

use App\Http\Controllers\Api\Accounting\FiscalYearClosingController;
use App\Http\Controllers\Api\Accounting\FiscalYearStatusController;
use App\Http\Controllers\Api\Accounting\AccountMappingHealthController;
use App\Http\Controllers\Api\Accounting\PeriodLockController;
use App\Http\Controllers\Api\Accounting\PeriodEndController;
use Illuminate\Support\Facades\Route;

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

    Route::get('/period-end/status', [PeriodEndController::class, 'status'])
        ->middleware('permission:period_end.view');
    Route::get('/period-end/checklist', [PeriodEndController::class, 'checklist'])
        ->middleware('permission:period_end.view');
    Route::post('/period-end/run', [PeriodEndController::class, 'run'])
        ->middleware('permission:period_end.run');
    Route::post('/period-end/reopen', [PeriodEndController::class, 'reopen'])
        ->middleware('permission:period_end.reopen');
});

Route::middleware(['auth:sanctum', 'company.access'])
    ->prefix('v1/accounting')
    ->group(function () {
        Route::get('/account-mapping-health', [AccountMappingHealthController::class, 'index'])
            ->middleware('permission:fiscal_year.view');
    });
