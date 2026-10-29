<?php

use App\Modules\Access\Controllers\AcceptInvitationController;
use App\Modules\Access\Controllers\AccessAuditController;
use App\Modules\Access\Controllers\CompanyInvitationAccessController;
use App\Modules\Access\Controllers\CompanyUserAccessController;
use App\Modules\Access\Controllers\PermissionCatalogController;
use Illuminate\Support\Facades\Route;

Route::post('/invitations/{token}/accept', AcceptInvitationController::class)->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'company.access'])->prefix('access')->group(function () {
    Route::get('/users', [CompanyUserAccessController::class, 'index'])->middleware('permission:access.users.view');
    Route::get('/company-users', [CompanyUserAccessController::class, 'index'])->middleware('permission:access.users.view');
    Route::get('/company-users/{companyUserId}', [CompanyUserAccessController::class, 'show'])->middleware('permission:access.users.view');
    Route::patch('/company-users/{companyUserId}/deactivate', [CompanyUserAccessController::class, 'deactivate'])->middleware('permission:access.users.delete');
    Route::patch('/company-users/{companyUserId}/reactivate', [CompanyUserAccessController::class, 'activate'])->middleware('permission:access.users.edit');
    Route::patch('/company-users/{companyUserId}/remove', [CompanyUserAccessController::class, 'remove'])->middleware('permission:access.users.delete');
    Route::get('/permission-catalog', PermissionCatalogController::class)->middleware('permission:access.users.view');
    Route::get('/permissions/catalog', PermissionCatalogController::class)->middleware('permission:access.users.view');
    Route::get('/users/{companyUserId}/permissions', [CompanyUserAccessController::class, 'permissions'])->middleware('permission:access.users.view');
    Route::put('/users/{companyUserId}/permissions', [CompanyUserAccessController::class, 'updatePermissions'])->middleware('permission:access.users.edit');
    Route::post('/users/{companyUserId}/copy-access', [CompanyUserAccessController::class, 'copyAccess'])->middleware('permission:access.users.edit');
    Route::get('/invitations', [CompanyInvitationAccessController::class, 'index'])->middleware('permission:access.invitations.view');
    Route::post('/invitations', [CompanyInvitationAccessController::class, 'store'])->middleware('permission:access.invitations.create');
    Route::post('/invitations/{id}/resend', [CompanyInvitationAccessController::class, 'resend'])->middleware('permission:access.invitations.create');
    Route::post('/invitations/{id}/revoke', [CompanyInvitationAccessController::class, 'revoke'])->middleware('permission:access.invitations.delete');
    Route::get('/audit', AccessAuditController::class)->middleware('permission:access.audit.view');
});
