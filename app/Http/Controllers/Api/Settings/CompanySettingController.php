<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCompanyAccountingSettingRequest;
use App\Http\Requests\Settings\UpdateCompanyModuleSettingRequest;
use App\Http\Requests\Settings\UpdateCompanyTransactionDefaultRequest;
use App\Services\Audit\AuditLogService;
use App\Services\Settings\CompanySettingService;
use App\Services\Tenant\TenantContext;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditEvent;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Throwable;

class CompanySettingController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CompanySettingService $service,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function show(): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }

        return $this->successResponse(
            $this->service->getSettings($company),
            'Company settings retrieved successfully'
        );
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
        ], 'Company workflow settings retrieved successfully');
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

        return $this->successResponse([
            'accounting' => $setting->toArray(),
        ], 'Accounting settings updated successfully');
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
