<?php

use App\Modules\OpeningBalance\Controllers\OpeningBalanceController;
use Illuminate\Support\Facades\Route;

/*
 * Parameter rute memakai {id}, bukan {batch}. Implicit route model binding
 * di-resolve oleh SubstituteBindings (grup middleware `api`) yang berjalan
 * sebelum `company.access`, sehingga koneksi `tenant` belum punya path database
 * saat binding menjalankan query — hasilnya TypeError 500. Modul lain memakai
 * pola yang sama: controller memuat model setelah middleware tenant siap.
 */
Route::middleware(['auth:sanctum', 'company.access'])->prefix('opening-balance')->group(function () {
    Route::get('/status', [OpeningBalanceController::class, 'status'])
        ->middleware('permission:opening_balance.view');
    Route::get('/batches', [OpeningBalanceController::class, 'index'])
        ->middleware('permission:opening_balance.view');
    Route::post('/batches', [OpeningBalanceController::class, 'store'])
        ->middleware('permission:opening_balance.manage');
    Route::get('/batches/{id}', [OpeningBalanceController::class, 'show'])
        ->middleware('permission:opening_balance.view');
    Route::patch('/batches/{id}', [OpeningBalanceController::class, 'update'])
        ->middleware('permission:opening_balance.manage');
    Route::put('/batches/{id}/lines', [OpeningBalanceController::class, 'replaceLines'])
        ->middleware('permission:opening_balance.manage');
    Route::post('/batches/{id}/validate', [OpeningBalanceController::class, 'validateBatch'])
        ->middleware('permission:opening_balance.validate');
    Route::get('/batches/{id}/preview', [OpeningBalanceController::class, 'preview'])
        ->middleware('permission:opening_balance.view');
    Route::post('/batches/{id}/post', [OpeningBalanceController::class, 'post'])
        ->middleware('permission:opening_balance.post');
    Route::post('/batches/{id}/lock', [OpeningBalanceController::class, 'lock'])
        ->middleware('permission:opening_balance.lock');
    Route::post('/batches/{id}/reopen', [OpeningBalanceController::class, 'reopen'])
        ->middleware('permission:opening_balance.reopen');
});
