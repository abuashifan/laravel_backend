<?php

use App\Http\Controllers\Api\Tenant\TenantContextTestController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->group(function () {
    Route::get('/tenant-context-test', TenantContextTestController::class);
});
