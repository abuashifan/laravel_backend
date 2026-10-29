<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Requests\UpdateCompanyAccountingSettingRequest;
use App\Modules\Settings\Requests\UpdateCompanyModuleSettingRequest;
use App\Modules\Settings\Requests\UpdateCompanyProfileRequest;
use App\Modules\Settings\Requests\UpdateCompanyTransactionDefaultRequest;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Api\ApiResponse;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditLogService;
use App\Shared\Permission\PermissionService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Throwable;

class CompanySettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CompanySettingService $service,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $auditLogService,
        private readonly PermissionService $permissions,
    ) {}

    public function show(): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }

        $settings = $this->service->getSettings($company);
        if (! $this->permissions->can('settings.company.view')) {
            $settings = [
                'accounting' => array_intersect_key($settings['accounting'], array_flip(UpdateCompanyAccountingSettingRequest::TRANSACTION_FIELDS)),
                'transaction_defaults' => $settings['transaction_defaults'],
            ];
        } elseif (! $this->permissions->can('settings.transactions.view')) {
            $settings['accounting'] = array_diff_key($settings['accounting'], array_flip(UpdateCompanyAccountingSettingRequest::TRANSACTION_FIELDS));
            unset($settings['transaction_defaults']);
        }

        return $this->successResponse($settings, 'Company settings retrieved successfully');
    }

    public function workflow(): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }

        $accounting = $this->service->getOrCreateAccountingSetting($company);

        return $this->successResponse([
            'transaction_workflow_mode' => $accounting->transaction_workflow_mode,
            'auto_post_transactions' => (bool) $accounting->auto_post_transactions,
            'approval_enabled' => (bool) $accounting->approval_enabled,
            'allow_void_transactions' => (bool) $accounting->allow_void_transactions,
            'transaction_edit_mode' => (string) ($accounting->transaction_edit_mode ?? 'lock'),
        ], 'Company workflow settings retrieved successfully');
    }

    public function updateProfile(UpdateCompanyProfileRequest $request): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }
        $old = $this->service->profile($company);
        $profile = $this->service->updateProfile($company, $request->validated());

        try {
            $this->auditLogService->logCentral([
                'event' => AuditEvent::SETTINGS_COMPANY_UPDATED,
                'action' => AuditAction::UPDATE,
                'module' => 'settings',
                'record_type' => 'company',
                'record_id' => (string) $company->id,
                'message' => 'Company profile updated.',
                'old_values' => $old,
                'new_values' => $profile,
            ]);
        } catch (Throwable $e) {
            // fail-safe
        }

        return $this->successResponse(['profile' => $profile], 'Company profile updated successfully');
    }

    public function updateAccounting(UpdateCompanyAccountingSettingRequest $request): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }
        $old = $this->service->getOrCreateAccountingSetting($company)->toArray();
        $setting = $this->service->updateAccountingSetting($company, $request->validated());

        try {
            $this->auditLogService->logCentral([
                'event' => AuditEvent::SETTINGS_COMPANY_UPDATED,
                'action' => AuditAction::UPDATE,
                'module' => 'settings',
                'record_type' => 'company_accounting_settings',
                'record_id' => (string) $company->id,
                'message' => 'Accounting settings updated.',
                'old_values' => $old,
                'new_values' => $setting->toArray(),
            ]);
        } catch (Throwable $e) {
            // fail-safe
        }

        $accounting = $setting->toArray();
        if (! $this->permissions->can('settings.company.view')) {
            $accounting = array_intersect_key($accounting, array_flip(UpdateCompanyAccountingSettingRequest::TRANSACTION_FIELDS));
        }

        return $this->successResponse(['accounting' => $accounting], 'Accounting settings updated successfully');
    }

    public function updateModules(UpdateCompanyModuleSettingRequest $request): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }
        $old = $this->service->getOrCreateModuleSetting($company)->toArray();
        $setting = $this->service->updateModuleSetting($company, $request->validated());

        try {
            $this->auditLogService->logCentral([
                'event' => AuditEvent::SETTINGS_MODULES_UPDATED,
                'action' => AuditAction::UPDATE,
                'module' => 'settings',
                'record_type' => 'company_module_settings',
                'record_id' => (string) $company->id,
                'message' => 'Module settings updated.',
                'old_values' => $old,
                'new_values' => $setting->toArray(),
            ]);
        } catch (Throwable $e) {
            // fail-safe
        }

        return $this->successResponse([
            'modules' => $setting->toArray(),
        ], 'Module settings updated successfully');
    }

    public function updateTransactionDefaults(UpdateCompanyTransactionDefaultRequest $request): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }

        $old = ['default_payment_term_id' => $this->service->getOrCreateAccountingSetting($company)->default_payment_term_id];
        $defaults = $this->service->updateTransactionDefaults($company, $request->validated());

        try {
            $this->auditLogService->logCentral([
                'event' => AuditEvent::SETTINGS_COMPANY_UPDATED,
                'action' => AuditAction::UPDATE,
                'module' => 'settings',
                'record_type' => 'company_transaction_defaults',
                'record_id' => (string) $company->id,
                'message' => 'Transaction defaults updated.',
                'old_values' => $old,
                'new_values' => $defaults,
            ]);
        } catch (Throwable $e) {
            // fail-safe
        }

        return $this->successResponse([
            'transaction_defaults' => $defaults,
        ], 'Transaction defaults updated successfully');
    }
}
