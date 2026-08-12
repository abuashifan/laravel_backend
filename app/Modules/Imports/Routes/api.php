<?php

use App\Modules\Imports\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    Route::get('/imports/templates/{profile}', [ImportController::class, 'template'])
        ->middleware('permission:imports.templates.view');
    Route::post('/imports', [ImportController::class, 'store'])
        ->middleware('permission:imports.upload');
    Route::patch('/imports/{uuid}/mapping', [ImportController::class, 'mapping'])
        ->middleware('permission:imports.map');
    Route::get('/imports/{uuid}', [ImportController::class, 'show'])
        ->middleware('permission:imports.view');
    Route::get('/imports/{uuid}/rows', [ImportController::class, 'rows'])
        ->middleware('permission:imports.view');
    Route::post('/imports/{uuid}/commit', [ImportController::class, 'commit'])
        ->middleware('permission:imports.commit');
    Route::delete('/imports/{uuid}', [ImportController::class, 'destroy'])
        ->middleware('permission:imports.cancel');
});
