<?php

use App\Modules\Gudang\Controllers\GudangActivationController;
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
    Route::get('/bootstrap', [GudangActivationController::class, 'bootstrap']);
});
