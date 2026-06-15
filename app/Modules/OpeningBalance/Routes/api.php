<?php

use App\Http\Controllers\Api\OpeningBalance\OpeningBalanceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('opening-balance')->group(function () {
    Route::get('/status', [OpeningBalanceController::class, 'status'])
        ->middleware('permission:opening_balance.view');
    Route::get('/batches', [OpeningBalanceController::class, 'index'])
        ->middleware('permission:opening_balance.view');
    Route::post('/batches', [OpeningBalanceController::class, 'store'])
        ->middleware('permission:opening_balance.manage');
    Route::get('/batches/{batch}', [OpeningBalanceController::class, 'show'])
        ->middleware('permission:opening_balance.view');
    Route::patch('/batches/{batch}', [OpeningBalanceController::class, 'update'])
        ->middleware('permission:opening_balance.manage');
    Route::put('/batches/{batch}/lines', [OpeningBalanceController::class, 'replaceLines'])
        ->middleware('permission:opening_balance.manage');
    Route::post('/batches/{batch}/validate', [OpeningBalanceController::class, 'validateBatch'])
        ->middleware('permission:opening_balance.validate');
    Route::get('/batches/{batch}/preview', [OpeningBalanceController::class, 'preview'])
        ->middleware('permission:opening_balance.view');
    Route::post('/batches/{batch}/post', [OpeningBalanceController::class, 'post'])
        ->middleware('permission:opening_balance.post');
    Route::post('/batches/{batch}/lock', [OpeningBalanceController::class, 'lock'])
        ->middleware('permission:opening_balance.lock');
    Route::post('/batches/{batch}/reopen', [OpeningBalanceController::class, 'reopen'])
        ->middleware('permission:opening_balance.reopen');
});
