<?php

use App\Http\Controllers\Api\Setup\SetupWizardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('setup')->group(function () {
    Route::get('/status', [SetupWizardController::class, 'status'])
        ->middleware('permission:setup.view');
    Route::get('/steps', [SetupWizardController::class, 'steps'])
        ->middleware('permission:setup.view');
    Route::patch('/current-step', [SetupWizardController::class, 'updateCurrentStep'])
        ->middleware('permission:setup.edit');
    Route::post('/validate-step', [SetupWizardController::class, 'validateStep'])
        ->middleware('permission:setup.validate');
    Route::post('/validate-all', [SetupWizardController::class, 'validateAll'])
        ->middleware('permission:setup.validate');
    Route::get('/opening-balance/preview', [SetupWizardController::class, 'openingBalancePreview'])
        ->middleware('permission:setup.view');
    Route::post('/finalize', [SetupWizardController::class, 'finalize'])
        ->middleware('permission:setup.finalize');
    Route::post('/reopen', [SetupWizardController::class, 'reopen'])
        ->middleware('permission:setup.reopen');
});
