<?php

use App\Modules\Workspace\Controllers\WorkspaceBudgetController;
use App\Modules\Workspace\Controllers\WorkspaceController;
use App\Modules\Workspace\Controllers\WorkspaceReportController;
use App\Modules\Workspace\Controllers\WorkspaceTeamController;
use App\Modules\Workspace\Controllers\WorkspaceUnitController;
use App\Modules\Workspace\Support\BudgetPermission;
use Illuminate\Support\Facades\Route;

Route::get('/workspace/status', [WorkspaceController::class, 'status'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'company.access:workspace', 'product.subscription:workspace'])->prefix('workspace')->group(function () {
    Route::get('/bootstrap', [WorkspaceController::class, 'bootstrap']);
    Route::get('/units', [WorkspaceUnitController::class, 'index'])->middleware('permission:workspace.units.view|workspace.units.manage|workspace.members.manage');
    Route::post('/units', [WorkspaceUnitController::class, 'store'])->middleware('permission:workspace.units.manage');
    Route::put('/units/{unit}', [WorkspaceUnitController::class, 'update'])->whereNumber('unit')->middleware('permission:workspace.units.manage');

    Route::get('/team', [WorkspaceTeamController::class, 'index'])->middleware('permission:workspace.members.manage|'.BudgetPermission::MANAGE_ACCESS);
    Route::put('/team/{member}/budget-access', [WorkspaceTeamController::class, 'updateBudgetAccess'])
        ->whereNumber('member')->middleware('permission:'.BudgetPermission::MANAGE_ACCESS);

    Route::middleware('permission:workspace.members.manage')->group(function () {
        Route::post('/invitations', [WorkspaceTeamController::class, 'invite'])->middleware('throttle:10,1');
        Route::delete('/invitations/{invitation}', [WorkspaceTeamController::class, 'revoke'])->whereNumber('invitation');
        Route::get('/units/{unit}/members', [WorkspaceUnitController::class, 'members'])->whereNumber('unit');
        Route::put('/units/{unit}/members/{member}', [WorkspaceUnitController::class, 'assign'])->whereNumber(['unit', 'member']);
        Route::delete('/units/{unit}/members/{member}', [WorkspaceUnitController::class, 'remove'])->whereNumber(['unit', 'member']);
    });
    Route::prefix('reports')->middleware('permission:'.BudgetPermission::DOCUMENT_ACCESS)->group(function () {
        Route::get('/units', [WorkspaceReportController::class, 'units']);
        Route::get('/units/{unit}', [WorkspaceReportController::class, 'unit'])->whereNumber('unit');
        Route::get('/activities/{document}', [WorkspaceReportController::class, 'activity'])->whereNumber('document');
        Route::get('/reconciliation', [WorkspaceReportController::class, 'reconciliation']);
    });

    Route::prefix('budget')->group(function () {
        Route::get('/periods', [WorkspaceBudgetController::class, 'periods'])->middleware('permission:'.BudgetPermission::DOCUMENT_ACCESS.'|'.BudgetPermission::MANAGE_PERIODS);
        Route::post('/periods', [WorkspaceBudgetController::class, 'createPeriod'])->middleware('permission:'.BudgetPermission::MANAGE_PERIODS);

        Route::middleware('permission:'.BudgetPermission::DOCUMENT_ACCESS)->group(function () {
            Route::get('/units', [WorkspaceBudgetController::class, 'units']);
            Route::get('/items', [WorkspaceBudgetController::class, 'items']);
            Route::get('/requests', [WorkspaceBudgetController::class, 'index']);
            Route::get('/requests/{document}', [WorkspaceBudgetController::class, 'show'])->whereNumber('document');
        });

        Route::middleware('permission:'.BudgetPermission::SUBMIT)->group(function () {
            Route::post('/requests', [WorkspaceBudgetController::class, 'store']);
            Route::put('/requests/{document}', [WorkspaceBudgetController::class, 'update'])->whereNumber('document');
            Route::post('/requests/{document}/submit', [WorkspaceBudgetController::class, 'submit'])->whereNumber('document');
            Route::post('/requests/{document}/revise', [WorkspaceBudgetController::class, 'revise'])->whereNumber('document');
        });

        Route::post('/requests/{document}/close', [WorkspaceBudgetController::class, 'closeActivity'])
            ->whereNumber('document')->middleware('permission:'.BudgetPermission::APPROVE_ACTIVITY);

        Route::post('/requests/{document}/accounting-sync', [WorkspaceBudgetController::class, 'retryAccounting'])
            ->whereNumber('document')->middleware('permission:'.BudgetPermission::SUBMIT.'|'.BudgetPermission::APPROVE_ACTIVITY);

        Route::post('/requests/{document}/{decision}', [WorkspaceBudgetController::class, 'decide'])
            ->whereNumber('document')->whereIn('decision', ['approve', 'reject'])->middleware('permission:'.BudgetPermission::APPROVAL);
    });
});
