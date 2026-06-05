<?php

use App\Http\Controllers\Api\Access\AccessAuditController;
use App\Http\Controllers\Api\Access\CompanyInvitationAccessController;
use App\Http\Controllers\Api\Access\CompanyUserAccessController;
use App\Http\Controllers\Api\Access\PermissionCatalogController;
use App\Http\Controllers\Api\Access\RoleAccessController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'company.access'])->prefix('access')->group(function () {
    Route::get('/users', [CompanyUserAccessController::class, 'index'])->middleware('permission:access.users.view');
    Route::get('/company-users', [CompanyUserAccessController::class, 'index'])->middleware('permission:access.users.view');
    Route::get('/company-users/{companyUserId}', [CompanyUserAccessController::class, 'show'])->middleware('permission:access.users.view');
    Route::patch('/company-users/{companyUserId}/role', [CompanyUserAccessController::class, 'updateRole'])->middleware('permission:access.users.manage');
    Route::patch('/company-users/{companyUserId}/deactivate', [CompanyUserAccessController::class, 'deactivate'])->middleware('permission:access.users.deactivate');
    Route::patch('/company-users/{companyUserId}/reactivate', [CompanyUserAccessController::class, 'activate'])->middleware('permission:access.users.manage');
    Route::patch('/company-users/{companyUserId}/remove', [CompanyUserAccessController::class, 'remove'])->middleware('permission:access.users.remove');

    Route::get('/permission-catalog', PermissionCatalogController::class)->middleware('permission:access.permissions.view');
    Route::get('/permissions/catalog', PermissionCatalogController::class)->middleware('permission:access.permissions.view');
    Route::get('/users/{companyUserId}/permissions', [CompanyUserAccessController::class, 'permissions'])->middleware('permission:access.permissions.view');
    Route::put('/users/{companyUserId}/permissions', [CompanyUserAccessController::class, 'updatePermissions'])->middleware('permission:access.permissions.manage');
    Route::post('/users/{companyUserId}/copy-access', [CompanyUserAccessController::class, 'copyAccess'])->middleware('permission:access.permissions.manage');
    Route::post('/users/{companyUserId}/reset-permissions', [CompanyUserAccessController::class, 'resetPermissions'])->middleware('permission:access.permissions.manage');

    Route::get('/roles', [RoleAccessController::class, 'index'])->middleware('permission:access.roles.view');
    Route::post('/roles', [RoleAccessController::class, 'store'])->middleware('permission:access.roles.create');
    Route::get('/roles/{roleId}', [RoleAccessController::class, 'show'])->middleware('permission:access.roles.view');
    Route::patch('/roles/{roleId}', [RoleAccessController::class, 'update'])->middleware('permission:access.roles.edit');
    Route::post('/roles/{roleId}/clone', [RoleAccessController::class, 'cloneRole'])->middleware('permission:access.roles.clone');
    Route::get('/roles/{roleId}/permissions', [RoleAccessController::class, 'show'])->middleware('permission:access.permissions.view');
    Route::put('/roles/{roleId}/permissions', [RoleAccessController::class, 'updatePermissions'])->middleware('permission:access.permissions.manage');
    Route::patch('/roles/{roleId}/deactivate', [RoleAccessController::class, 'deactivate'])->middleware('permission:access.roles.deactivate');
    Route::patch('/roles/{roleId}/reactivate', [RoleAccessController::class, 'activate'])->middleware('permission:access.roles.edit');

    Route::get('/invitations', [CompanyInvitationAccessController::class, 'index'])->middleware('permission:access.invitations.view');
    Route::post('/invitations', [CompanyInvitationAccessController::class, 'store'])->middleware('permission:access.invitations.create');
    Route::post('/invitations/{id}/resend', [CompanyInvitationAccessController::class, 'resend'])->middleware('permission:access.invitations.resend');
    Route::post('/invitations/{id}/revoke', [CompanyInvitationAccessController::class, 'revoke'])->middleware('permission:access.invitations.revoke');

    Route::get('/audit', AccessAuditController::class)->middleware('permission:access.audit.view');
});
