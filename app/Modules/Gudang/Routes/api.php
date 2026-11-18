<?php

use App\Modules\Gudang\Controllers\GudangActivationController;
use App\Modules\Gudang\Controllers\InventoryController;
use Illuminate\Support\Facades\Route;

// Finlite Gudang. Rute data memakai `company.access:gudang`, yaitu tenant Gudang
// milik perusahaan -- bukan tenant akuntansi atau Kasir.
Route::middleware(['auth:sanctum'])->prefix('gudang')->group(function () {
    // Dipanggil sebelum Gudang aktif, jadi belum bisa memakai tenant Gudang.
    Route::get('/status', [GudangActivationController::class, 'status']);
    Route::post('/activate', [GudangActivationController::class, 'activate']);
});

// `product.subscription:gudang`: langganan pemilik habis = hanya-baca (402 untuk tulis).
Route::middleware(['auth:sanctum', 'company.access:gudang', 'product.subscription:gudang'])->prefix('gudang')->group(function () {
    Route::get('/items', [InventoryController::class, 'items'])->middleware('permission:gudang.items.view|gudang.items.create|gudang.receipts.create');
    Route::post('/items', [InventoryController::class, 'createItem'])->middleware('permission:gudang.items.create');
    Route::get('/locations', [InventoryController::class, 'locations'])->middleware('permission:gudang.warehouses.view|gudang.warehouses.create|gudang.receipts.create');
    Route::post('/locations', [InventoryController::class, 'createLocation'])->middleware('permission:gudang.warehouses.create');
    Route::post('/receipts', [InventoryController::class, 'receive'])->middleware('permission:gudang.receipts.create');
    Route::get('/stock', [InventoryController::class, 'balances'])->middleware('permission:gudang.stock.view');
    Route::get('/items/{item}/card', [InventoryController::class, 'card'])->whereNumber('item')->middleware('permission:gudang.stock.view');
    Route::get('/bootstrap', [GudangActivationController::class, 'bootstrap']);
});
