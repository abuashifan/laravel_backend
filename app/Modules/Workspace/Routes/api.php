<?php

use App\Modules\Workspace\Controllers\WorkspaceController;
use App\Modules\Workspace\Controllers\WorkspaceTeamController;
use App\Modules\Workspace\Controllers\WorkspaceUnitController;
use Illuminate\Support\Facades\Route;

Route::get('/workspace/status', [WorkspaceController::class, 'status'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'company.access:workspace', 'product.subscription:workspace'])->prefix('workspace')->group(function () {
    Route::get('/bootstrap', [WorkspaceController::class, 'bootstrap']);
    Route::get('/units', [WorkspaceUnitController::class, 'index'])->middleware('permission:workspace.units.view|workspace.units.manage|workspace.members.manage');
    Route::post('/units', [WorkspaceUnitController::class, 'store'])->middleware('permission:workspace.units.manage');
    Route::put('/units/{unit}', [WorkspaceUnitController::class, 'update'])->whereNumber('unit')->middleware('permission:workspace.units.manage');

    Route::middleware('permission:workspace.members.manage')->group(function () {
        Route::get('/team', [WorkspaceTeamController::class, 'index']);
        Route::post('/invitations', [WorkspaceTeamController::class, 'invite'])->middleware('throttle:10,1');
        Route::delete('/invitations/{invitation}', [WorkspaceTeamController::class, 'revoke'])->whereNumber('invitation');
        Route::get('/units/{unit}/members', [WorkspaceUnitController::class, 'members'])->whereNumber('unit');
        Route::put('/units/{unit}/members/{member}', [WorkspaceUnitController::class, 'assign'])->whereNumber(['unit', 'member']);
        Route::delete('/units/{unit}/members/{member}', [WorkspaceUnitController::class, 'remove'])->whereNumber(['unit', 'member']);
    });
});
