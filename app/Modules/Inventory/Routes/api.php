<?php

use App\Http\Controllers\Api\Inventory\InventoryReportController;
use App\Http\Controllers\Api\Inventory\InventoryValuationController;
use App\Http\Controllers\Api\Inventory\StockAdjustmentController;
use App\Http\Controllers\Api\Inventory\StockBalanceController;
use App\Http\Controllers\Api\Inventory\StockMovementController;
use App\Http\Controllers\Api\Inventory\StockOpnameController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('inventory')->group(function () {
    Route::get('/stock-balances', [StockBalanceController::class, 'index'])
        ->middleware('permission:inventory.stock.view');
    Route::get('/stock-balances/product/{productId}', [StockBalanceController::class, 'byProduct'])
        ->middleware('permission:inventory.stock.view');
    Route::get('/stock-balances/warehouse/{warehouseId}', [StockBalanceController::class, 'byWarehouse'])
        ->middleware('permission:inventory.stock.view');

    Route::get('/stock-movements', [StockMovementController::class, 'index'])
        ->middleware('permission:inventory.movements.view');
    Route::post('/stock-movements', [StockMovementController::class, 'store'])
        ->middleware('permission:inventory.movements.create');
    Route::get('/stock-movements/{id}', [StockMovementController::class, 'show'])
        ->middleware('permission:inventory.movements.view');
    Route::patch('/stock-movements/{id}/post', [StockMovementController::class, 'post'])
        ->middleware('permission:inventory.movements.post');
    Route::patch('/stock-movements/{id}/void', [StockMovementController::class, 'void'])
        ->middleware('permission:inventory.movements.void');

    Route::get('/stock-adjustments', [StockAdjustmentController::class, 'index'])
        ->middleware('permission:inventory.adjustments.view');
    Route::post('/stock-adjustments', [StockAdjustmentController::class, 'store'])
        ->middleware('permission:inventory.adjustments.create');
    Route::get('/stock-adjustments/{id}', [StockAdjustmentController::class, 'show'])
        ->middleware('permission:inventory.adjustments.view');
    Route::patch('/stock-adjustments/{id}', [StockAdjustmentController::class, 'update'])
        ->middleware('permission:inventory.adjustments.edit');
    Route::patch('/stock-adjustments/{id}/approve', [StockAdjustmentController::class, 'approve'])
        ->middleware('permission:inventory.adjustments.approve');
    Route::patch('/stock-adjustments/{id}/post', [StockAdjustmentController::class, 'post'])
        ->middleware('permission:inventory.adjustments.post');
    Route::patch('/stock-adjustments/{id}/void', [StockAdjustmentController::class, 'void'])
        ->middleware('permission:inventory.adjustments.void');

    Route::get('/stock-opnames', [StockOpnameController::class, 'index'])
        ->middleware('permission:inventory.opname.view');
    Route::post('/stock-opnames', [StockOpnameController::class, 'store'])
        ->middleware('permission:inventory.opname.create');
    Route::get('/stock-opnames/{id}', [StockOpnameController::class, 'show'])
        ->middleware('permission:inventory.opname.view');
    Route::post('/stock-opnames/{id}/generate-lines', [StockOpnameController::class, 'generateLines'])
        ->middleware('permission:inventory.opname.edit');
    Route::patch('/stock-opnames/{id}/lines/{lineId}', [StockOpnameController::class, 'updateLine'])
        ->middleware('permission:inventory.opname.edit');
    Route::patch('/stock-opnames/{id}/counted', [StockOpnameController::class, 'markCounted'])
        ->middleware('permission:inventory.opname.edit');
    Route::patch('/stock-opnames/{id}/finalize', [StockOpnameController::class, 'finalize'])
        ->middleware('permission:inventory.opname.finalize');
    Route::patch('/stock-opnames/{id}/void', [StockOpnameController::class, 'void'])
        ->middleware('permission:inventory.opname.finalize');

    Route::prefix('reports')->group(function () {
        Route::get('/stock-balances', [InventoryReportController::class, 'stockBalances'])->middleware('permission:inventory.reports.view');
        Route::get('/stock-movements', [InventoryReportController::class, 'stockMovements'])->middleware('permission:inventory.reports.view');
        Route::get('/stock-card', [InventoryReportController::class, 'stockCard'])->middleware('permission:inventory.reports.view');
        Route::get('/valuation', [InventoryReportController::class, 'valuation'])->middleware('permission:inventory.reports.view');
        Route::get('/low-stock', [InventoryReportController::class, 'lowStock'])->middleware('permission:inventory.reports.view');
        Route::get('/negative-stock', [InventoryReportController::class, 'negativeStock'])->middleware('permission:inventory.reports.view');
    });

    Route::get('/valuation', [InventoryValuationController::class, 'current'])
        ->middleware('permission:inventory.valuation.view');
    Route::get('/valuation/as-of', [InventoryValuationController::class, 'asOf'])
        ->middleware('permission:inventory.valuation.view');
    Route::get('/valuation/products/{productId}', [InventoryValuationController::class, 'byProduct'])
        ->middleware('permission:inventory.valuation.view');
    Route::get('/valuation/warehouses/{warehouseId}', [InventoryValuationController::class, 'byWarehouse'])
        ->middleware('permission:inventory.valuation.view');
});
