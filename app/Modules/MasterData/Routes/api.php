<?php

use App\Modules\MasterData\Controllers\AccountMappingController;
use App\Modules\MasterData\Controllers\ChartOfAccountController;
use App\Modules\MasterData\Controllers\ContactController;
use App\Modules\MasterData\Controllers\DepartmentController;
use App\Modules\MasterData\Controllers\PaymentTermController;
use App\Modules\MasterData\Controllers\ProductCategoryController;
use App\Modules\MasterData\Controllers\ProductController;
use App\Modules\MasterData\Controllers\ProjectController;
use App\Modules\MasterData\Controllers\UnitController;
use App\Modules\MasterData\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

// NOTE: Phase 1B demo endpoint `/api/my-companies-demo` has been disabled in Phase 2A.

Route::middleware(['auth:sanctum', 'company.access'])->prefix('master-data')->group(function () {
    // Chart of Accounts
    Route::get('/chart-of-accounts', [ChartOfAccountController::class, 'index'])->middleware('permission:master_data.coa.view');
    // Harus sebelum `/chart-of-accounts/{id}` supaya "adjacent" tidak tertangkap sebagai id.
    Route::get('/chart-of-accounts/adjacent', [ChartOfAccountController::class, 'adjacent'])->middleware('permission:master_data.coa.view');
    Route::post('/chart-of-accounts', [ChartOfAccountController::class, 'store'])->middleware('permission:master_data.coa.create');
    Route::get('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'show'])->middleware('permission:master_data.coa.view');
    Route::patch('/chart-of-accounts/{id}', [ChartOfAccountController::class, 'update'])->middleware('permission:master_data.coa.edit');
    Route::patch('/chart-of-accounts/{id}/deactivate', [ChartOfAccountController::class, 'deactivate'])->middleware('permission:master_data.coa.delete');
    Route::patch('/chart-of-accounts/{id}/activate', [ChartOfAccountController::class, 'activate'])->middleware('permission:master_data.coa.edit');

    // Contacts
    Route::get('/contacts', [ContactController::class, 'index'])->middleware('permission:master_data.contacts.view');
    Route::get('/contacts/adjacent', [ContactController::class, 'adjacent'])->middleware('permission:master_data.contacts.view');
    Route::post('/contacts', [ContactController::class, 'store'])->middleware('permission:master_data.contacts.create');
    Route::get('/contacts/{id}', [ContactController::class, 'show'])->middleware('permission:master_data.contacts.view');
    Route::patch('/contacts/{id}', [ContactController::class, 'update'])->middleware('permission:master_data.contacts.edit');
    Route::patch('/contacts/{id}/deactivate', [ContactController::class, 'deactivate'])->middleware('permission:master_data.contacts.delete');
    Route::patch('/contacts/{id}/activate', [ContactController::class, 'activate'])->middleware('permission:master_data.contacts.edit');

    // Payment Terms
    Route::get('/payment-terms', [PaymentTermController::class, 'index'])->middleware('permission:master_data.payment_terms.view');
    Route::post('/payment-terms', [PaymentTermController::class, 'store'])->middleware('permission:master_data.payment_terms.create');
    Route::get('/payment-terms/{id}', [PaymentTermController::class, 'show'])->middleware('permission:master_data.payment_terms.view');
    Route::patch('/payment-terms/{id}', [PaymentTermController::class, 'update'])->middleware('permission:master_data.payment_terms.edit');
    Route::patch('/payment-terms/{id}/deactivate', [PaymentTermController::class, 'deactivate'])->middleware('permission:master_data.payment_terms.delete');
    Route::patch('/payment-terms/{id}/activate', [PaymentTermController::class, 'activate'])->middleware('permission:master_data.payment_terms.edit');

    // Units
    Route::get('/units', [UnitController::class, 'index'])->middleware('permission:master_data.units.view');
    Route::post('/units', [UnitController::class, 'store'])->middleware('permission:master_data.units.create');
    Route::get('/units/{id}', [UnitController::class, 'show'])->middleware('permission:master_data.units.view');
    Route::patch('/units/{id}', [UnitController::class, 'update'])->middleware('permission:master_data.units.edit');
    Route::patch('/units/{id}/deactivate', [UnitController::class, 'deactivate'])->middleware('permission:master_data.units.delete');
    Route::patch('/units/{id}/activate', [UnitController::class, 'activate'])->middleware('permission:master_data.units.edit');

    // Product Categories
    Route::get('/product-categories', [ProductCategoryController::class, 'index'])->middleware('permission:master_data.products.view');
    Route::post('/product-categories', [ProductCategoryController::class, 'store'])->middleware('permission:master_data.products.create');
    Route::get('/product-categories/{id}', [ProductCategoryController::class, 'show'])->middleware('permission:master_data.products.view');
    Route::patch('/product-categories/{id}', [ProductCategoryController::class, 'update'])->middleware('permission:master_data.products.edit');
    Route::patch('/product-categories/{id}/deactivate', [ProductCategoryController::class, 'deactivate'])->middleware('permission:master_data.products.delete');
    Route::patch('/product-categories/{id}/activate', [ProductCategoryController::class, 'activate'])->middleware('permission:master_data.products.edit');

    // Products
    Route::get('/products', [ProductController::class, 'index'])->middleware('permission:master_data.products.view');
    Route::get('/products/adjacent', [ProductController::class, 'adjacent'])->middleware('permission:master_data.products.view');
    Route::post('/products', [ProductController::class, 'store'])->middleware('permission:master_data.products.create');
    Route::get('/products/{id}', [ProductController::class, 'show'])->middleware('permission:master_data.products.view');
    Route::patch('/products/{id}', [ProductController::class, 'update'])->middleware('permission:master_data.products.edit');
    Route::patch('/products/{id}/deactivate', [ProductController::class, 'deactivate'])->middleware('permission:master_data.products.delete');
    Route::patch('/products/{id}/activate', [ProductController::class, 'activate'])->middleware('permission:master_data.products.edit');

    // Warehouses
    Route::get('/warehouses', [WarehouseController::class, 'index'])->middleware('permission:master_data.warehouses.view');
    Route::post('/warehouses', [WarehouseController::class, 'store'])->middleware('permission:master_data.warehouses.create');
    Route::get('/warehouses/{id}', [WarehouseController::class, 'show'])->middleware('permission:master_data.warehouses.view');
    Route::patch('/warehouses/{id}', [WarehouseController::class, 'update'])->middleware('permission:master_data.warehouses.edit');
    Route::patch('/warehouses/{id}/deactivate', [WarehouseController::class, 'deactivate'])->middleware('permission:master_data.warehouses.delete');
    Route::patch('/warehouses/{id}/activate', [WarehouseController::class, 'activate'])->middleware('permission:master_data.warehouses.edit');

    // Departments (Analytical Dimensions)
    Route::get('/departments', [DepartmentController::class, 'index'])->middleware('permission:master_data.departments.view');
    Route::post('/departments', [DepartmentController::class, 'store'])->middleware('permission:master_data.departments.create');
    Route::get('/departments/{id}', [DepartmentController::class, 'show'])->middleware('permission:master_data.departments.view');
    Route::patch('/departments/{id}', [DepartmentController::class, 'update'])->middleware('permission:master_data.departments.edit');
    Route::patch('/departments/{id}/deactivate', [DepartmentController::class, 'deactivate'])->middleware('permission:master_data.departments.delete');
    Route::patch('/departments/{id}/activate', [DepartmentController::class, 'activate'])->middleware('permission:master_data.departments.edit');

    // Projects (Analytical Dimensions)
    Route::get('/projects', [ProjectController::class, 'index'])->middleware('permission:master_data.projects.view');
    Route::post('/projects', [ProjectController::class, 'store'])->middleware('permission:master_data.projects.create');
    Route::get('/projects/{id}', [ProjectController::class, 'show'])->middleware('permission:master_data.projects.view');
    Route::patch('/projects/{id}', [ProjectController::class, 'update'])->middleware('permission:master_data.projects.edit');
    Route::patch('/projects/{id}/deactivate', [ProjectController::class, 'deactivate'])->middleware('permission:master_data.projects.delete');
    Route::patch('/projects/{id}/activate', [ProjectController::class, 'activate'])->middleware('permission:master_data.projects.edit');

    // Account Mappings
    Route::get('/account-mappings', [AccountMappingController::class, 'index'])->middleware('permission:settings.account_mapping.view');
    Route::post('/account-mappings/import', [AccountMappingController::class, 'import'])->middleware('permission:settings.account_mapping.edit');
    Route::post('/account-mappings/import/sheets', [AccountMappingController::class, 'importSheets'])->middleware('permission:settings.account_mapping.edit');
    Route::post('/account-mappings/reset-defaults', [AccountMappingController::class, 'resetDefaults'])->middleware('permission:settings.account_mapping.edit');
    Route::get('/account-mappings/import-template', [AccountMappingController::class, 'importTemplate'])->middleware('permission:settings.account_mapping.view');
    Route::patch('/account-mappings/{mappingKey}', [AccountMappingController::class, 'update'])->middleware('permission:settings.account_mapping.edit');
});
