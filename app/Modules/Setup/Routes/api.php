<?php

use App\Modules\Setup\Controllers\CoaTemplateController;
use App\Modules\Setup\Controllers\SetupWizardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('setup')->group(function () {
    Route::get('/status', [SetupWizardController::class, 'status'])
        ->middleware('permission:setup.wizard.view');
    Route::get('/steps', [SetupWizardController::class, 'steps'])
        ->middleware('permission:setup.wizard.view');
    Route::get('/coa-templates', [CoaTemplateController::class, 'index'])
        ->middleware('permission:setup.wizard.view');
    Route::post('/coa-templates/apply', [CoaTemplateController::class, 'apply'])
        ->middleware('permission:setup.wizard.edit');
    Route::post('/coa-templates/import', [CoaTemplateController::class, 'import'])
        ->middleware('permission:setup.wizard.edit');
    Route::post('/coa-templates/import/sheets', [CoaTemplateController::class, 'importSheets'])
        ->middleware('permission:setup.wizard.edit');
    Route::get('/coa-templates/import-template', [CoaTemplateController::class, 'importTemplate'])
        ->middleware('permission:setup.wizard.view');
    Route::patch('/current-step', [SetupWizardController::class, 'updateCurrentStep'])
        ->middleware('permission:setup.wizard.edit');
    Route::post('/validate-step', [SetupWizardController::class, 'validateStep'])
        ->middleware('permission:setup.wizard.edit');
    Route::post('/validate-all', [SetupWizardController::class, 'validateAll'])
        ->middleware('permission:setup.wizard.edit');
    Route::get('/opening-balance/preview', [SetupWizardController::class, 'openingBalancePreview'])
        ->middleware('permission:setup.wizard.view');
    Route::post('/finalize', [SetupWizardController::class, 'finalize'])
        ->middleware('permission:setup.wizard.approve');
    Route::post('/reopen', [SetupWizardController::class, 'reopen'])
        ->middleware('permission:setup.wizard.reopen');
});
