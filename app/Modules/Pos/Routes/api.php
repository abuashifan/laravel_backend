<?php

use App\Modules\Pos\Controllers\PosActivationController;
use App\Modules\Pos\Controllers\PosCatalogController;
use App\Modules\Pos\Controllers\PosCustomerController;
use App\Modules\Pos\Controllers\PosReportController;
use App\Modules\Pos\Controllers\PosSaleController;
use App\Modules\Pos\Controllers\PosShiftController;
use Illuminate\Support\Facades\Route;

// Finlite POS. Semua rute data memakai `company.access:pos`, yaitu tenant POS
// milik perusahaan -- bukan tenant akuntansi.

Route::middleware(['auth:sanctum'])->prefix('pos')->group(function () {
    // Dipanggil sebelum POS aktif, jadi belum bisa memakai tenant POS.
    Route::get('/status', [PosActivationController::class, 'status']);
    Route::post('/activate', [PosActivationController::class, 'activate']);
});

Route::middleware(['auth:sanctum', 'company.access:pos'])->prefix('pos')->group(function () {
    Route::get('/bootstrap', [PosActivationController::class, 'bootstrap']);
    Route::get('/settings', [PosReportController::class, 'settings']);
    Route::patch('/settings', [PosReportController::class, 'updateSettings'])->middleware('permission:pos.settings.edit');

    // Katalog -- layar kasir butuh daftar produk, jadi izin jual juga cukup untuk membaca.
    Route::get('/categories', [PosCatalogController::class, 'categories'])->middleware('permission:pos.products.view|pos.sales.create');
    Route::post('/categories', [PosCatalogController::class, 'storeCategory'])->middleware('permission:pos.products.create');
    Route::patch('/categories/{id}', [PosCatalogController::class, 'updateCategory'])->middleware('permission:pos.products.edit');
    Route::delete('/categories/{id}', [PosCatalogController::class, 'destroyCategory'])->middleware('permission:pos.products.delete');

    Route::get('/products', [PosCatalogController::class, 'products'])->middleware('permission:pos.products.view|pos.sales.create');
    Route::get('/products/{id}', [PosCatalogController::class, 'showProduct'])->middleware('permission:pos.products.view|pos.sales.create');
    Route::post('/products', [PosCatalogController::class, 'storeProduct'])->middleware('permission:pos.products.create');
    Route::patch('/products/{id}', [PosCatalogController::class, 'updateProduct'])->middleware('permission:pos.products.edit');
    Route::delete('/products/{id}', [PosCatalogController::class, 'destroyProduct'])->middleware('permission:pos.products.delete');
    Route::post('/products/{id}/stock', [PosCatalogController::class, 'adjustStock'])->middleware('permission:pos.stock.adjust');
    Route::get('/products/{id}/stock-movements', [PosCatalogController::class, 'stockMovements'])->middleware('permission:pos.products.view');

    // Pelanggan & kasbon -- kasir boleh mencari & menambah pelanggan cepat saat menjual.
    Route::get('/customers', [PosCustomerController::class, 'index'])->middleware('permission:pos.customers.view|pos.sales.create');
    Route::get('/customers/{id}', [PosCustomerController::class, 'show'])->middleware('permission:pos.customers.view|pos.sales.create');
    Route::post('/customers', [PosCustomerController::class, 'store'])->middleware('permission:pos.customers.create|pos.sales.create');
    Route::patch('/customers/{id}', [PosCustomerController::class, 'update'])->middleware('permission:pos.customers.edit');
    Route::delete('/customers/{id}', [PosCustomerController::class, 'destroy'])->middleware('permission:pos.customers.delete');
    Route::get('/customers/{id}/credit', [PosCustomerController::class, 'credit'])->middleware('permission:pos.customers.view');
    Route::post('/customers/{id}/payments', [PosCustomerController::class, 'pay'])->middleware('permission:pos.customers.edit|pos.sales.create');

    // Shift kasir.
    Route::get('/shifts', [PosShiftController::class, 'index'])->middleware('permission:pos.shifts.view');
    Route::get('/shifts/current', [PosShiftController::class, 'current'])->middleware('permission:pos.shifts.view|pos.sales.create');
    Route::get('/shifts/{id}', [PosShiftController::class, 'show'])->middleware('permission:pos.shifts.view|pos.sales.create');
    Route::post('/shifts', [PosShiftController::class, 'open'])->middleware('permission:pos.shifts.create');
    Route::post('/shifts/{id}/close', [PosShiftController::class, 'close'])->middleware('permission:pos.shifts.create');

    // Penjualan.
    Route::get('/sales', [PosSaleController::class, 'index'])->middleware('permission:pos.sales.view');
    Route::get('/sales/{id}', [PosSaleController::class, 'show'])->middleware('permission:pos.sales.view|pos.sales.create');
    Route::post('/sales', [PosSaleController::class, 'store'])->middleware('permission:pos.sales.create');
    Route::post('/sales/{id}/void', [PosSaleController::class, 'void'])->middleware('permission:pos.sales.delete');

    Route::get('/reports/summary', [PosReportController::class, 'summary'])->middleware('permission:pos.reports.view');
});
