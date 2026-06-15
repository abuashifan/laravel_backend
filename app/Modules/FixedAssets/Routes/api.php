<?php

use App\Http\Controllers\Api\FixedAssets\FixedAssetCategoryController;
use App\Http\Controllers\Api\FixedAssets\FixedAssetController;
use App\Http\Controllers\Api\FixedAssets\FixedAssetReportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('fixed-assets')->group(function () {
    Route::get('/categories', [FixedAssetCategoryController::class, 'index'])
        ->middleware('permission:fixed_assets.settings.view');
    Route::post('/categories', [FixedAssetCategoryController::class, 'store'])
        ->middleware('permission:fixed_assets.settings.manage');
    Route::patch('/categories/{id}', [FixedAssetCategoryController::class, 'update'])
        ->middleware('permission:fixed_assets.settings.manage');

    Route::get('/', [FixedAssetController::class, 'index'])
        ->middleware('permission:fixed_assets.view');
    Route::post('/', [FixedAssetController::class, 'store'])
        ->middleware('permission:fixed_assets.create');

    Route::prefix('reports')->group(function () {
        Route::get('/register', [FixedAssetReportController::class, 'register'])
            ->middleware('permission:fixed_assets.reports.view');
        Route::get('/depreciation', [FixedAssetReportController::class, 'depreciation'])
            ->middleware('permission:fixed_assets.reports.view');
        Route::get('/disposals', [FixedAssetReportController::class, 'disposals'])
            ->middleware('permission:fixed_assets.reports.view');
        Route::get('/reconciliation', [FixedAssetReportController::class, 'reconciliation'])
            ->middleware('permission:fixed_assets.reports.view');
    });

    Route::get('/{id}', [FixedAssetController::class, 'show'])
        ->middleware('permission:fixed_assets.view');
    Route::patch('/{id}', [FixedAssetController::class, 'update'])
        ->middleware('permission:fixed_assets.edit');
    Route::post('/{id}/capitalize', [FixedAssetController::class, 'capitalize'])
        ->middleware('permission:fixed_assets.capitalize');
    Route::post('/{id}/dispose', [FixedAssetController::class, 'dispose'])
        ->middleware('permission:fixed_assets.dispose');

});
