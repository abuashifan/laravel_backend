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
Route::middleware(['auth:sanctum', 'company.access', 'permission:master_data.import.execute'])
    ->prefix('imports/master')
    ->group(function () {
        Route::get('/profiles', [ImportController::class, 'profiles']);
        Route::get('/templates/{profile}', [ImportController::class, 'template']);
        Route::get('/batches', [ImportController::class, 'index']);
        Route::post('/', [ImportController::class, 'storeMaster']);
        Route::patch('/{uuid}/sheet', [ImportController::class, 'sheet']);
        Route::patch('/{uuid}/mapping', [ImportController::class, 'mapping']);
        Route::get('/{uuid}', [ImportController::class, 'show']);
        Route::get('/{uuid}/rows', [ImportController::class, 'rows']);
        Route::post('/{uuid}/commit', [ImportController::class, 'commit']);
        Route::post('/{uuid}/revert', [ImportController::class, 'revert'])->middleware('permission:master_data.import.revert');
        Route::delete('/{uuid}', [ImportController::class, 'destroy']);
        Route::get('/{uuid}/export-errors', [ImportController::class, 'exportErrors']);
    });

// ── Transaction imports (Pro+) ───────────────────────────────────────
Route::middleware(['auth:sanctum', 'company.access', 'permission:accounting.journals.import|sales.invoices.import|purchase.bills.import'])
    ->prefix('imports/transactions')
    ->group(function () {
        Route::get('/profiles', [ImportController::class, 'profiles']);
        Route::get('/templates/{profile}', [ImportController::class, 'template']);
        Route::get('/batches', [ImportController::class, 'index']);
        Route::post('/', [ImportController::class, 'storeTransaction']);
        Route::patch('/{uuid}/sheet', [ImportController::class, 'sheet']);
        Route::patch('/{uuid}/mapping', [ImportController::class, 'mapping']);
        Route::get('/{uuid}', [ImportController::class, 'show']);
        Route::get('/{uuid}/rows', [ImportController::class, 'rows']);
        Route::post('/{uuid}/commit', [ImportController::class, 'commit']);
        Route::post('/{uuid}/revert', [ImportController::class, 'revert'])->middleware('permission:master_data.import.revert');
        Route::delete('/{uuid}', [ImportController::class, 'destroy']);
        Route::get('/{uuid}/export-errors', [ImportController::class, 'exportErrors']);
    });

// ── Legacy routes (backward compatibility, tanpa tier gate) ──────────
// Dipertahankan agar test dan frontend yang belum diperbarui tidak pecah.
// Middleware tier hanya berlaku di rute master/transactions di atas.
Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    Route::get('/imports', [ImportController::class, 'index'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::get('/imports/profiles', [ImportController::class, 'profiles'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::get('/imports/templates/{profile}', [ImportController::class, 'template'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::post('/imports', [ImportController::class, 'store'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::patch('/imports/{uuid}/sheet', [ImportController::class, 'sheet'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::patch('/imports/{uuid}/mapping', [ImportController::class, 'mapping'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::get('/imports/{uuid}', [ImportController::class, 'show'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::get('/imports/{uuid}/rows', [ImportController::class, 'rows'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::post('/imports/{uuid}/commit', [ImportController::class, 'commit'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::post('/imports/{uuid}/revert', [ImportController::class, 'revert'])
        ->middleware('permission:master_data.import.revert');
    Route::delete('/imports/{uuid}', [ImportController::class, 'destroy'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
    Route::get('/imports/{uuid}/export-errors', [ImportController::class, 'exportErrors'])
        ->middleware('permission:master_data.import.execute|accounting.journals.import|sales.invoices.import|purchase.bills.import');
});
