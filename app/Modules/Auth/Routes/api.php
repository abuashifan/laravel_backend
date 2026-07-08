<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\PermissionController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    Route::get('/auth/permissions', [PermissionController::class, 'index']);
});
