<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\PermissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    // Sengaja tidak ada route register: akun client dibuat owner aplikasi lewat
    // `php artisan user:create`. Membuka registrasi mandiri berarti siapa pun
    // bisa membuat tenant database sendiri lewat POST /companies.
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    Route::get('/auth/permissions', [PermissionController::class, 'index']);
});
