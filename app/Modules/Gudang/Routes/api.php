<?php

use App\Modules\Gudang\Controllers\CountController;
use App\Modules\Gudang\Controllers\DocumentController;
use App\Modules\Gudang\Controllers\GudangActivationController;
use App\Modules\Gudang\Controllers\ImportController;
use App\Modules\Gudang\Controllers\InsightController;
use App\Modules\Gudang\Controllers\InventoryController;
use App\Modules\Gudang\Controllers\OutboundOrderController;
use App\Modules\Gudang\Controllers\ReportController;
use App\Modules\Gudang\Controllers\SignupController;
use App\Modules\Gudang\Controllers\StockControlController;
use App\Modules\Gudang\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::post('/gudang/verification-code', [SignupController::class, 'code'])->middleware('throttle:3,10');
Route::post('/gudang/register', [SignupController::class, 'register'])->middleware('throttle:5,10');

// Finlite Gudang. Rute data memakai `company.access:gudang`, yaitu tenant Gudang
// milik perusahaan -- bukan tenant akuntansi atau Kasir.
Route::middleware(['auth:sanctum'])->prefix('gudang')->group(function () {
    // Dipanggil sebelum Gudang aktif, jadi belum bisa memakai tenant Gudang.
    Route::get('/status', [GudangActivationController::class, 'status']);
    Route::post('/activate', [GudangActivationController::class, 'activate']);
});

// `product.subscription:gudang`: langganan pemilik habis = hanya-baca (402 untuk tulis).
Route::middleware(['auth:sanctum', 'company.access:gudang', 'product.subscription:gudang'])->prefix('gudang')->group(function () {
    Route::get('/insights', [InsightController::class, 'show']);
    Route::get('/labels', [InsightController::class, 'labels']);
    Route::get('/units', [StockControlController::class, 'units']);
    Route::post('/units', [StockControlController::class, 'units']);
    Route::put('/units/{id}', [StockControlController::class, 'units'])->whereNumber('id');
    Route::get('/batches', [StockControlController::class, 'batches']);
    Route::post('/batches', [StockControlController::class, 'batches']);
    Route::patch('/batches/{id}', [StockControlController::class, 'batches'])->whereNumber('id');
    Route::get('/batches/{id}/trace', [StockControlController::class, 'trace'])->whereNumber('id');
    Route::get('/batch-stock', [StockControlController::class, 'batchStock']);
    Route::get('/stock-rules', [StockControlController::class, 'stockRules']);
    Route::put('/stock-rules', [StockControlController::class, 'stockRules']);
    Route::get('/counts/due', [CountController::class, 'due']);
    Route::get('/counts', [CountController::class, 'index']);
    Route::post('/counts', [CountController::class, 'store']);
    Route::get('/counts/{id}', [CountController::class, 'show'])->whereNumber('id');
    Route::post('/counts/{id}/lines', [CountController::class, 'lines'])->whereNumber('id');
    Route::post('/counts/{id}/actions', [CountController::class, 'act'])->whereNumber('id');
    Route::get('/items', [InventoryController::class, 'items'])->middleware('permission:gudang.items.view|gudang.items.create|gudang.items.edit|gudang.receipts.create|gudang.issues.create|gudang.transfers.create|gudang.stock.adjust|gudang.stock.approve|gudang.stock.view|gudang.settings.edit|gudang.counts.view|gudang.counts.create|gudang.counts.count|gudang.counts.approve');
    Route::post('/items', [InventoryController::class, 'createItem'])->middleware('permission:gudang.items.create');
    Route::get('/locations', [InventoryController::class, 'locations'])->middleware('permission:gudang.warehouses.view|gudang.warehouses.create|gudang.stock.view|gudang.items.create|gudang.settings.edit|gudang.receipts.create|gudang.issues.create|gudang.transfers.create|gudang.stock.adjust|gudang.stock.approve|gudang.counts.view|gudang.counts.create|gudang.counts.count|gudang.counts.approve|gudang.items.view|gudang.items.edit');
    Route::post('/locations', [InventoryController::class, 'createLocation'])->middleware('permission:gudang.warehouses.create');
    Route::post('/receipts', [InventoryController::class, 'receive'])->middleware('permission:gudang.receipts.create');
    Route::get('/stock', [InventoryController::class, 'balances'])->middleware('permission:gudang.stock.view');
    Route::get('/items/{item}/card', [InventoryController::class, 'card'])->whereNumber('item')->middleware('permission:gudang.stock.view');
    Route::patch('/items/{id}', [InventoryController::class, 'updateItem'])->whereNumber('id')->middleware('permission:gudang.items.edit');
    Route::get('/items/{id}/photo', [WarehouseController::class, 'photo'])->whereNumber('id')->middleware('permission:gudang.items.view|gudang.items.edit');
    Route::post('/items/{id}/photo', [WarehouseController::class, 'setPhoto'])->whereNumber('id')->middleware('permission:gudang.items.edit');
    Route::post('/warehouses', [WarehouseController::class, 'create'])->middleware('permission:gudang.warehouses.create');
    Route::get('/members', [WarehouseController::class, 'members'])->middleware('permission:gudang.settings.edit');
    Route::put('/members/{id}', [WarehouseController::class, 'assign'])->whereNumber('id')->middleware('permission:gudang.settings.edit');
    Route::get('/settings', [WarehouseController::class, 'settings'])->middleware('permission:gudang.settings.view|gudang.settings.edit');
    Route::put('/settings', [WarehouseController::class, 'setSettings'])->middleware('permission:gudang.settings.edit');
    Route::get('/parties', [WarehouseController::class, 'parties'])->middleware('permission:gudang.items.view|gudang.items.create|gudang.receipts.create|gudang.issues.create|gudang.transfers.create|gudang.stock.adjust|gudang.stock.approve');
    Route::post('/parties', [WarehouseController::class, 'party'])->middleware('permission:gudang.items.create');
    Route::get('/documents', [DocumentController::class, 'index'])->middleware('permission:gudang.receipts.create|gudang.issues.create|gudang.transfers.create|gudang.stock.adjust|gudang.stock.approve');
    Route::post('/documents', [DocumentController::class, 'store'])->middleware('permission:gudang.receipts.create|gudang.issues.create|gudang.transfers.create|gudang.stock.adjust|gudang.stock.approve');
    Route::post('/documents/{id}/actions', [DocumentController::class, 'act'])->whereNumber('id')->middleware('permission:gudang.receipts.create|gudang.issues.create|gudang.transfers.create|gudang.stock.adjust|gudang.stock.approve');
    Route::post('/documents/{id}/reverse', [DocumentController::class, 'reverse'])->whereNumber('id')->middleware('permission:gudang.stock.approve');
    Route::middleware('permission:gudang.issues.create')->group(function () {
        Route::get('/outbound-orders', [OutboundOrderController::class, 'index']);
        Route::post('/outbound-orders', [OutboundOrderController::class, 'store']);
        Route::post('/outbound-orders/{id}/actions', [OutboundOrderController::class, 'act'])->whereNumber('id');
    });
    Route::get('/reports', [ReportController::class, 'show'])->middleware('permission:gudang.stock.view|gudang.audit.view');
    Route::get('/import/template', [ImportController::class, 'template'])->middleware('permission:gudang.items.create');
    Route::post('/import', [ImportController::class, 'upload'])->middleware('permission:gudang.items.create');
    Route::get('/bootstrap', [GudangActivationController::class, 'bootstrap']);
});
