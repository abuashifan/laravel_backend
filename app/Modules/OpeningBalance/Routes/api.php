<?php

use App\Modules\OpeningBalance\Controllers\OpeningBalanceController;
use Illuminate\Support\Facades\Route;

/*
 * Fase 8: saldo awal bukan lagi dokumen tersendiri.
 *
 * Tidak ada batch, tidak ada lines, tidak ada validate/post/lock/reopen —
 * jurnal pembuka adalah jurnal biasa bersumber `opening_balance`, dan yang
 * tersisa di sini cuma tiga hal yang memang khas saldo awal: menetapkan
 * tanggalnya, menutup perantaranya, dan membatalkan salah satu jurnalnya.
 */
Route::middleware(['auth:sanctum', 'company.access'])->prefix('opening-balance')->group(function () {
    Route::get('/status', [OpeningBalanceController::class, 'status'])
        ->middleware('permission:accounting.opening_balance.view');
    Route::put('/opening-date', [OpeningBalanceController::class, 'setOpeningDate'])
        ->middleware('permission:accounting.opening_balance.edit');
    Route::post('/close-clearing', [OpeningBalanceController::class, 'closeClearing'])
        ->middleware('permission:accounting.opening_balance.approve');
    Route::delete('/journals/{id}', [OpeningBalanceController::class, 'voidJournal'])
        ->middleware('permission:accounting.opening_balance.reopen');
});
