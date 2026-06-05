<?php

use App\Http\Controllers\Api\Journal\JournalEntryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    // Phase 6: Journal Entry Engine (manual journals only). No DELETE routes.
    Route::get('/journals', [JournalEntryController::class, 'index'])->middleware('permission:journal.view');
    Route::post('/journals', [JournalEntryController::class, 'store'])->middleware('permission:journal.create');
    Route::get('/journals/{id}', [JournalEntryController::class, 'show'])->middleware('permission:journal.view');
    Route::patch('/journals/{id}', [JournalEntryController::class, 'update'])->middleware('permission:journal.edit');
    Route::post('/journals/{id}/approve', [JournalEntryController::class, 'approve'])->middleware('permission:journal.approve');
    Route::post('/journals/{id}/post', [JournalEntryController::class, 'post'])->middleware('permission:journal.post');
    Route::post('/journals/{id}/void', [JournalEntryController::class, 'void'])->middleware('permission:journal.void');
});
