<?php

use App\Modules\Companies\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/companies', [CompanyController::class, 'index']);
    Route::post('/companies/select', [CompanyController::class, 'select']);
});
