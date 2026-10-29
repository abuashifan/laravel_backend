<?php

use App\Modules\Journal\Controllers\JournalEntryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    // Phase 6: Journal Entry Engine (manual journals only). DELETE mengikuti mode lock/open.
    Route::get('/journals', [JournalEntryController::class, 'index'])->middleware('permission:accounting.journals.view');
    // Harus sebelum `/journals/{id}` supaya "adjacent" tidak tertangkap sebagai id.
    Route::get('/journals/adjacent', [JournalEntryController::class, 'adjacent'])->middleware('permission:accounting.journals.view');
    Route::post('/journals', [JournalEntryController::class, 'store'])->middleware('permission:accounting.journals.create');
    Route::get('/journals/{id}', [JournalEntryController::class, 'show'])->middleware('permission:accounting.journals.view');
    Route::patch('/journals/{id}', [JournalEntryController::class, 'update'])->middleware('permission:accounting.journals.edit');
    Route::post('/journals/{id}/approve', [JournalEntryController::class, 'approve'])->middleware('permission:accounting.journals.approve');
    Route::post('/journals/{id}/post', [JournalEntryController::class, 'post'])->middleware('permission:accounting.journals.approve');
    Route::delete('/journals/{id}', [JournalEntryController::class, 'destroy'])->middleware('permission:accounting.journals.delete');
    Route::post('/journals/{id}/void', [JournalEntryController::class, 'void'])->middleware('permission:accounting.journals.delete');
});
