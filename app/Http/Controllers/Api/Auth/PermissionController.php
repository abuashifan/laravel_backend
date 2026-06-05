<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\CompanyAccountingSetting;
use App\Services\Permissions\PermissionService;
use App\Services\Tenant\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly TenantContext $tenantContext
    ) {
    }

    public function index(): JsonResponse
    {
        $role = $this->tenantContext->role();
        $companyId = $this->tenantContext->companyId();

        $permissionMode = 'role_template';

        if ($companyId) {
            $setting = CompanyAccountingSetting::query()
                ->where('company_id', $companyId)
                ->first();

            if ($setting?->user_permission_mode) {
                $permissionMode = $setting->user_permission_mode;
            }
        }

        return $this->successResponse([
            'role' => $role,
            'permission_mode' => $permissionMode,
            'permissions' => $this->permissionService->userPermissions(),
        ], 'Permissions retrieved successfully');
    }
}

