<?php

use App\Modules\Pos\Controllers\PosActivationController;
use App\Modules\Pos\Controllers\PosAuditController;
use App\Modules\Pos\Controllers\PosCatalogController;
use App\Modules\Pos\Controllers\PosCustomerController;
use App\Modules\Pos\Controllers\PosInventoryController;
use App\Modules\Pos\Controllers\PosReportController;
use App\Modules\Pos\Controllers\PosSaleController;
use App\Modules\Pos\Controllers\PosSecurityController;
use App\Modules\Pos\Controllers\PosShiftController;
use App\Modules\Pos\Controllers\PosSignupController;
use App\Modules\Pos\Controllers\PosTeamController;
use App\Modules\Pos\Services\PosReportTableService;
use Illuminate\Support\Facades\Route;

// Finlite POS. Semua rute data memakai `company.access:pos`, yaitu tenant POS
// milik perusahaan -- bukan tenant akuntansi.

// Pendaftaran mandiri Finlite Kasir (Fase 2). Trial dimulai saat email
// terverifikasi, jadi toko (tenant) hanya bisa lahir dari email yang nyata.
Route::prefix('pos')->group(function () {
    Route::post('/register', [PosSignupController::class, 'register'])->middleware('throttle:5,1');
    Route::post('/verify-email', [PosSignupController::class, 'verify'])->middleware('throttle:20,1');
});

Route::middleware(['auth:sanctum'])->prefix('pos')->group(function () {
    Route::get('/account', [PosSignupController::class, 'account']);
    Route::post('/verify-email/resend', [PosSignupController::class, 'resend'])->middleware('throttle:3,1');
    Route::post('/subscription/free', [PosSignupController::class, 'claimFree'])->middleware('throttle:5,1');
    // Dipanggil sebelum POS aktif, jadi belum bisa memakai tenant POS.
    Route::get('/status', [PosActivationController::class, 'status']);
    Route::post('/activate', [PosActivationController::class, 'activate']);
});

// `product.subscription:pos`: langganan pemilik habis = hanya-baca (402 untuk tulis).
Route::middleware(['auth:sanctum', 'company.access:pos', 'product.subscription:pos'])->prefix('pos')->group(function () {
    Route::get('/bootstrap', [PosActivationController::class, 'bootstrap']);
    Route::get('/settings', [PosReportController::class, 'settings']);
    Route::patch('/settings', [PosReportController::class, 'updateSettings'])->middleware('permission:pos.settings.edit');

    // Katalog -- layar kasir butuh daftar produk, jadi izin jual juga cukup untuk membaca.
    Route::get('/categories', [PosCatalogController::class, 'categories'])->middleware('permission:pos.products.view|pos.sales.create');
    Route::post('/categories', [PosCatalogController::class, 'storeCategory'])->middleware('permission:pos.products.create');
    Route::patch('/categories/{id}', [PosCatalogController::class, 'updateCategory'])->middleware('permission:pos.products.edit');
    Route::delete('/categories/{id}', [PosCatalogController::class, 'destroyCategory'])->middleware('permission:pos.products.delete');

    Route::get('/products', [PosCatalogController::class, 'products'])->middleware('permission:pos.products.view|pos.sales.create');
    // Impor/ekspor didaftarkan sebelum /products/{id}.
    Route::get('/products/export', [PosCatalogController::class, 'exportProducts'])->middleware('permission:pos.products.view');
    Route::post('/products/import', [PosCatalogController::class, 'importProducts'])->middleware('permission:pos.products.create');
    Route::get('/products/{id}', [PosCatalogController::class, 'showProduct'])->middleware('permission:pos.products.view|pos.sales.create');
    Route::post('/products', [PosCatalogController::class, 'storeProduct'])->middleware('permission:pos.products.create');
    Route::patch('/products/{id}', [PosCatalogController::class, 'updateProduct'])->middleware('permission:pos.products.edit');
    Route::delete('/products/{id}', [PosCatalogController::class, 'destroyProduct'])->middleware('permission:pos.products.delete');
    Route::post('/products/{id}/stock', [PosCatalogController::class, 'adjustStock'])->middleware('permission:pos.stock.adjust');
    Route::get('/products/{id}/photo', [PosCatalogController::class, 'photo'])->middleware('permission:pos.products.view|pos.sales.create');
    Route::post('/products/{id}/photo', [PosCatalogController::class, 'storePhoto'])->middleware('permission:pos.products.edit');
    Route::delete('/products/{id}/photo', [PosCatalogController::class, 'destroyPhoto'])->middleware('permission:pos.products.edit');
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
    // Batal dan retur: kepala toko (izin delete) langsung; kasir hanya dengan PIN
    // kepala toko dan hanya bila pemilik mengizinkan -- dijaga di controller
    // (`PosSecurityService::authorizeSensitive`).
    Route::post('/sales/{id}/void', [PosSaleController::class, 'void'])->middleware('permission:pos.sales.delete|pos.sales.create');
    Route::get('/returns', [PosSaleController::class, 'returns'])->middleware('permission:pos.sales.view');
    Route::post('/sales/{id}/returns', [PosSaleController::class, 'storeReturn'])->middleware('permission:pos.sales.delete|pos.sales.create');

    // Pemasok, stok masuk, stok opname -- baca: lihat produk; tulis: ubah stok.
    Route::get('/suppliers', [PosInventoryController::class, 'suppliers'])->middleware('permission:pos.products.view');
    Route::get('/suppliers/{id}', [PosInventoryController::class, 'showSupplier'])->middleware('permission:pos.products.view');
    Route::post('/suppliers', [PosInventoryController::class, 'storeSupplier'])->middleware('permission:pos.stock.adjust');
    Route::patch('/suppliers/{id}', [PosInventoryController::class, 'updateSupplier'])->middleware('permission:pos.stock.adjust');
    Route::delete('/suppliers/{id}', [PosInventoryController::class, 'destroySupplier'])->middleware('permission:pos.stock.adjust');
    Route::post('/suppliers/{id}/payments', [PosInventoryController::class, 'paySupplier'])->middleware('permission:pos.stock.adjust');
    Route::get('/purchases', [PosInventoryController::class, 'purchases'])->middleware('permission:pos.products.view');
    Route::get('/purchases/{id}', [PosInventoryController::class, 'showPurchase'])->middleware('permission:pos.products.view');
    Route::post('/purchases', [PosInventoryController::class, 'storePurchase'])->middleware('permission:pos.stock.adjust');
    Route::get('/stock-counts', [PosInventoryController::class, 'stockCounts'])->middleware('permission:pos.products.view');
    Route::get('/stock-counts/{id}', [PosInventoryController::class, 'showStockCount'])->middleware('permission:pos.products.view');
    Route::post('/stock-counts', [PosInventoryController::class, 'storeStockCount'])->middleware('permission:pos.stock.adjust');

    // Fase 4b: log aktivitas, PIN kepala toko.
    Route::get('/audit-logs', [PosAuditController::class, 'index'])->middleware('permission:pos.audit.view');
    Route::get('/audit-logs/actions', [PosAuditController::class, 'actions'])->middleware('permission:pos.audit.view');
    Route::put('/security/pin', [PosSecurityController::class, 'setPin'])->middleware('permission:pos.settings.edit');
    Route::delete('/security/pin', [PosSecurityController::class, 'clearPin'])->middleware('permission:pos.settings.edit');

    Route::get('/reports/summary', [PosReportController::class, 'summary'])->middleware('permission:pos.reports.view');
    Route::get('/reports/dashboard', [PosReportController::class, 'dashboard'])->middleware('permission:pos.reports.view');
    Route::get('/reports/{type}', [PosReportController::class, 'table'])->whereIn('type', PosReportTableService::TYPES)->middleware('permission:pos.reports.view');
    Route::get('/reports/{type}/export', [PosReportController::class, 'exportTable'])->whereIn('type', PosReportTableService::TYPES)->middleware('permission:pos.reports.export');

    // Pengguna toko: undang kasir dengan templat izin, ubah peran, nonaktifkan.
    // Memakai izin `access.*` yang sama dengan layar Akses di Finlite Accounting.
    Route::get('/team', [PosTeamController::class, 'index'])->middleware('permission:access.users.view');
    Route::post('/team/invitations', [PosTeamController::class, 'invite'])->middleware('permission:access.invitations.create');
    Route::post('/team/invitations/{id}/revoke', [PosTeamController::class, 'revoke'])->middleware('permission:access.invitations.delete');
    Route::patch('/team/members/{id}', [PosTeamController::class, 'updateMember'])->middleware('permission:access.users.edit');
});
