<?php

use App\Http\Controllers\Api\Settings\CompanySettingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
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
