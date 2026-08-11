<?php

use App\Modules\Admin\Controllers\AdminAuthController;
use App\Modules\Admin\Controllers\ClientUserController;
use Illuminate\Support\Facades\Route;

/**
 * Area pengelolaan client oleh owner aplikasi.
 *
 * Tidak satu pun route di sini memakai `company.access`. Itu disengaja:
 * pengelola aplikasi mengatur akun, status, dan paket client — bukan membaca
 * data keuangan mereka. Jangan tambahkan endpoint di grup ini yang menyentuh
 * koneksi tenant.
 */
Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'platform.admin'])->group(function () {
        Route::get('/me', [AdminAuthController::class, 'me']);
        Route::post('/logout', [AdminAuthController::class, 'logout']);

        Route::get('/plans', [ClientUserController::class, 'plans']);
        Route::get('/clients', [ClientUserController::class, 'index']);
        Route::get('/clients/{id}', [ClientUserController::class, 'show']);
        Route::post('/clients', [ClientUserController::class, 'store']);
        Route::patch('/clients/{id}', [ClientUserController::class, 'update']);
        Route::patch('/clients/{id}/plan', [ClientUserController::class, 'updatePlan']);
        Route::post('/clients/{id}/reset-password', [ClientUserController::class, 'resetPassword']);
    });
});
