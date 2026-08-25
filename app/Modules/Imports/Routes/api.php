<?php

use App\Modules\Imports\Controllers\ImportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Import Routes — Fase 5: dipisah per tier
|--------------------------------------------------------------------------
|
| /imports/master/*      → masterdata.import (Basic+)
| /imports/transactions/* → transactions.import (Pro+)
|
| Profil master: contact, product, chart_of_account, opening_balance, fixed_asset_opening
| Profil transaksi: sales_invoice, vendor_bill, journal_entry
|
| Controller yang sama dipakai dua grup — validasi profil dilakukan
| di controller berdasarkan prefix rute, bukan di middleware.
*/

$masterProfiles = ['contact', 'product', 'chart_of_account', 'opening_balance', 'fixed_asset_opening'];
$transactionProfiles = ['sales_invoice', 'vendor_bill', 'journal_entry'];

// ── Master data imports (Basic+) ─────────────────────────────────────
Route::middleware(['auth:sanctum', 'company.access', 'permission:masterdata.import'])
    ->prefix('imports/master')
    ->group(function () use ($masterProfiles) {
        Route::get('/profiles', [ImportController::class, 'profiles']);
        Route::get('/templates/{profile}', [ImportController::class, 'template']);
        Route::post('/', [ImportController::class, 'storeMaster']);
        Route::patch('/{uuid}/mapping', [ImportController::class, 'mapping']);
        Route::get('/{uuid}', [ImportController::class, 'show']);
        Route::get('/{uuid}/rows', [ImportController::class, 'rows']);
        Route::post('/{uuid}/commit', [ImportController::class, 'commit']);
        Route::delete('/{uuid}', [ImportController::class, 'destroy']);
        Route::get('/{uuid}/export-errors', [ImportController::class, 'exportErrors']);
    });

// ── Transaction imports (Pro+) ───────────────────────────────────────
Route::middleware(['auth:sanctum', 'company.access', 'permission:transactions.import'])
    ->prefix('imports/transactions')
    ->group(function () use ($transactionProfiles) {
        Route::get('/profiles', [ImportController::class, 'profiles']);
        Route::get('/templates/{profile}', [ImportController::class, 'template']);
        Route::post('/', [ImportController::class, 'storeTransaction']);
        Route::patch('/{uuid}/mapping', [ImportController::class, 'mapping']);
        Route::get('/{uuid}', [ImportController::class, 'show']);
        Route::get('/{uuid}/rows', [ImportController::class, 'rows']);
        Route::post('/{uuid}/commit', [ImportController::class, 'commit']);
        Route::delete('/{uuid}', [ImportController::class, 'destroy']);
        Route::get('/{uuid}/export-errors', [ImportController::class, 'exportErrors']);
    });

// ── Legacy routes (backward compatibility, tanpa tier gate) ──────────
// Dipertahankan agar test dan frontend yang belum diperbarui tidak pecah.
// Middleware tier hanya berlaku di rute master/transactions di atas.
Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    Route::get('/imports/profiles', [ImportController::class, 'profiles'])
        ->middleware('permission:imports.view');
    Route::get('/imports/templates/{profile}', [ImportController::class, 'template'])
        ->middleware('permission:imports.templates.view');
    Route::post('/imports', [ImportController::class, 'store'])
        ->middleware('permission:imports.upload');
    Route::patch('/imports/{uuid}/mapping', [ImportController::class, 'mapping'])
        ->middleware('permission:imports.map');
    Route::get('/imports/{uuid}', [ImportController::class, 'show'])
        ->middleware('permission:imports.view');
    Route::get('/imports/{uuid}/rows', [ImportController::class, 'rows'])
        ->middleware('permission:imports.view');
    Route::post('/imports/{uuid}/commit', [ImportController::class, 'commit'])
        ->middleware('permission:imports.commit');
    Route::delete('/imports/{uuid}', [ImportController::class, 'destroy'])
        ->middleware('permission:imports.cancel');
    Route::get('/imports/{uuid}/export-errors', [ImportController::class, 'exportErrors'])
        ->middleware('permission:imports.view');
});
