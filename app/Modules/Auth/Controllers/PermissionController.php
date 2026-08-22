<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\CompanyAccountingSetting;
use App\Shared\Permission\PermissionService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;

class PermissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly TenantContext $tenantContext
    ) {}

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
            // Dua daftar, bukan satu. `permissions` sudah disaring paket dan
            // dipakai guard; `plan_features` menjawab KENAPA sesuatu hilang —
            // tanpa itu UI tidak bisa membedakan "minta ke admin perusahaan"
            // dari "naikkan paket", dan kehilangan satu-satunya tempat wajar
            // untuk menawarkan upgrade.
            'plan_features' => $this->permissionService->planFeatures(),
        ], 'Permissions retrieved successfully');
    }
}
