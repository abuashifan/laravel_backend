<?php

use App\Http\Controllers\Api\Dashboard\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access', 'permission:dashboard.view'])
    ->prefix('dashboard')
    ->group(function () {
        Route::get('/summary', [DashboardController::class, 'summary']);
        Route::get('/pending', [DashboardController::class, 'pending']);
        Route::get('/chart', [DashboardController::class, 'chart']);
        Route::get('/activities', [DashboardController::class, 'activities']);
    });