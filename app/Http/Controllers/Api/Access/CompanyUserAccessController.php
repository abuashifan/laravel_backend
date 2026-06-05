<?php

namespace App\Http\Controllers\Api\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\CopyAccessRequest;
use App\Http\Requests\Access\UpdateCompanyUserPermissionRequest;
use App\Http\Requests\Access\UpdateCompanyUserRoleRequest;
use App\Exceptions\ApiException;
use App\Models\CompanyUser;
use App\Models\CompanyUserPermissionOverride;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogService;
use App\Services\Permissions\EffectivePermissionService;
use App\Services\Tenant\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyUserAccessController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EffectivePermissionService $effectivePermissionService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function index(): JsonResponse
    {
        return $this->successResponse(
            $this->companyUsersQuery()->get()->map(fn (CompanyUser $companyUser) => $this->companyUserPayload($companyUser))->all(),
            'Company users retrieved.'
        );
    }

    public function show(int $companyUserId): JsonResponse
    {
        return $this->successResponse($this->companyUserPayload($this->companyUser($companyUserId)), 'Company user retrieved.');
    }

    public function permissions(int $companyUserId): JsonResponse
    {
        $companyUser = $this->companyUser($companyUserId);

        return $this->successResponse($this->permissionPayload($companyUser), 'Effective permissions retrieved.');
    }

    public function updatePermissions(UpdateCompanyUserPermissionRequest $request, int $companyUserId): JsonResponse
    {
        $companyUser = $this->companyUser($companyUserId);
        $this->guardSelfPrivilegeChange($request, $companyUser);

        $data = $request->validated();

        DB::transaction(function () use ($companyUser, $data, $request): void {
            if (array_key_exists('role_id', $data)) {
                $role = $data['role_id'] ? $this->role((int) $data['role_id']) : null;
                $companyUser->forceFill([
                    'role_id' => $role?->id,
                    'role' => $role?->slug ?? $companyUser->role,
                ])->save();
            }

            if (array_key_exists('overrides', $data)) {
                $permissionIdsByKey = Permission::query()
                    ->whereIn('key', collect($data['overrides'])->pluck('permission_key')->all())
                    ->pluck('id', 'key');

                $companyUser->permissionOverrides()->delete();

                foreach ($data['overrides'] as $override) {
                    CompanyUserPermissionOverride::query()->create([
                        'company_user_id' => $companyUser->id,
                        'permission_id' => $permissionIdsByKey[$override['permission_key']],
                        'effect' => $override['effect'],
                        'reason' => $override['reason'] ?? null,
                        'created_by' => $request->user()?->id,
                        'updated_by' => $request->user()?->id,
                    ]);
                }
            }

            $this->audit('access.permissions.update', $companyUser, [
                'role_id' => $data['role_id'] ?? null,
                'override_count' => count($data['overrides'] ?? []),
            ], $request);
        });

        return $this->successResponse($this->permissionPayload($companyUser->refresh()), 'User permissions updated.');
    }

    public function updateRole(UpdateCompanyUserRoleRequest $request, int $companyUserId): JsonResponse
    {
        $companyUser = $this->companyUser($companyUserId);
        $this->guardSelfPrivilegeChange($request, $companyUser);
        $data = $request->validated();
        $role = isset($data['role_id']) && $data['role_id'] ? $this->role((int) $data['role_id']) : null;

        $companyUser->forceFill([
            'role_id' => $role?->id,
            'role' => $role?->slug ?? ($data['role'] ?? $companyUser->role),
        ])->save();

        if ($data['reset_overrides'] ?? false) {
            $companyUser->permissionOverrides()->delete();
        }

        $this->audit('access.user.role.update', $companyUser, $data, $request);

        return $this->successResponse($this->permissionPayload($companyUser->refresh()), 'Company user role updated.');
    }

    public function copyAccess(CopyAccessRequest $request, int $companyUserId): JsonResponse
    {
        $target = $this->companyUser($companyUserId);
        $this->guardSelfPrivilegeChange($request, $target);
        $source = $this->companyUser((int) $request->validated('source_company_user_id'));
        $copyRole = $request->boolean('copy_role', true);
        $copyOverrides = $request->boolean('copy_overrides', true);

        DB::transaction(function () use ($source, $target, $copyRole, $copyOverrides, $request): void {
            if ($copyRole) {
                $target->forceFill(['role_id' => $source->role_id, 'role' => $source->role])->save();
            }

            if ($copyOverrides) {
                $target->permissionOverrides()->delete();
                foreach ($source->permissionOverrides()->get() as $override) {
                    CompanyUserPermissionOverride::query()->create([
                        'company_user_id' => $target->id,
                        'permission_id' => $override->permission_id,
                        'effect' => $override->effect,
                        'reason' => 'Copied from company_user '.$source->id,
                        'created_by' => $request->user()?->id,
                        'updated_by' => $request->user()?->id,
                    ]);
                }
            }

            $this->audit('access.permissions.copy', $target, [
                'source_company_user_id' => $source->id,
                'copy_role' => $copyRole,
                'copy_overrides' => $copyOverrides,
            ], $request);
        });

        return $this->successResponse($this->permissionPayload($target->refresh()), 'Access copied.');
    }

    public function resetPermissions(Request $request, int $companyUserId): JsonResponse
    {
        $companyUser = $this->companyUser($companyUserId);
        $this->guardSelfPrivilegeChange($request, $companyUser);
        $companyUser->permissionOverrides()->delete();
        $this->audit('access.permissions.reset', $companyUser, [], $request);

        return $this->successResponse($this->permissionPayload($companyUser->refresh()), 'User permissions reset to role default.');
    }

    public function deactivate(Request $request, int $companyUserId): JsonResponse
    {
        $companyUser = $this->companyUser($companyUserId);
        $this->guardSelfPrivilegeChange($request, $companyUser);
        $companyUser->forceFill(['status' => 'inactive'])->save();
        $this->audit('access.user.deactivate', $companyUser, [], $request);

        return $this->successResponse($this->companyUserPayload($companyUser->refresh()), 'Company user deactivated.');
    }

    public function activate(Request $request, int $companyUserId): JsonResponse
    {
        $companyUser = $this->companyUser($companyUserId);
        if ($companyUser->status === 'active') {
            return $this->successResponse($this->companyUserPayload($companyUser), 'Company user already active.');
        }
        $companyUser->forceFill(['status' => 'active'])->save();
        $this->audit('access.user.activate', $companyUser, [], $request);

        return $this->successResponse($this->companyUserPayload($companyUser->refresh()), 'Company user activated.');
    }

    public function remove(Request $request, int $companyUserId): JsonResponse
    {
        $companyUser = $this->companyUser($companyUserId);
        $this->guardSelfPrivilegeChange($request, $companyUser);
        $companyUser->forceFill(['status' => 'removed'])->save();
        $this->audit('access.user.removed', $companyUser, [], $request);

        return $this->successResponse($this->companyUserPayload($companyUser->refresh()), 'Company user removed.');
    }

    private function companyUsersQuery()
    {
        return CompanyUser::query()
            ->with(['user:id,name,email,status', 'rolePreset:id,name,slug'])
            ->where('company_id', $this->tenantContext->company()?->id);
    }

    private function companyUser(int $companyUserId): CompanyUser
    {
        return $this->companyUsersQuery()->whereKey($companyUserId)->firstOrFail();
    }

    private function companyUserPayload(CompanyUser $companyUser): array
    {
        return [
            'id' => $companyUser->id,
            'company_id' => $companyUser->company_id,
            'user_id' => $companyUser->user_id,
            'name' => $companyUser->user?->name,
            'email' => $companyUser->user?->email,
            'role' => $companyUser->role,
            'role_id' => $companyUser->role_id,
            'role_name' => $companyUser->rolePreset?->name,
            'status' => $companyUser->status,
            'joined_at' => $companyUser->joined_at,
        ];
    }

    private function permissionPayload(CompanyUser $companyUser): array
    {
        $rolePermissions = $this->effectivePermissionService->rolePermissionsForCompanyUser($companyUser);
        $allow = $this->effectivePermissionService->getUserAllowOverrideKeys($companyUser);
        $deny = $this->effectivePermissionService->getUserDenyOverrideKeys($companyUser);
        $effective = $this->effectivePermissionService->getEffectivePermissionKeys($companyUser);

        return [
            'company_user' => $this->companyUserPayload($companyUser),
            'role_permission_keys' => $rolePermissions,
            'allow_override_keys' => $allow,
            'deny_override_keys' => $deny,
            'effective_permission_keys' => $effective,
            'overrides' => $companyUser->permissionOverrides()
                ->with('permission:id,key,label')
                ->get()
                ->map(fn (CompanyUserPermissionOverride $override) => [
                    'permission_key' => $override->permission?->key,
                    'effect' => $override->effect,
                    'reason' => $override->reason,
                ])
                ->all(),
        ];
    }

    private function guardSelfPrivilegeChange(Request $request, CompanyUser $target): void
    {
        if ($target->user_id === $request->user()?->id) {
            throw ApiException::make('SELF_ACCESS_CHANGE_NOT_ALLOWED', 'Users cannot deactivate, remove, or alter their own access.', 422);
        }

        if (in_array($target->role, ['owner', 'admin'], true)) {
            $remainingManagers = CompanyUser::query()
                ->where('company_id', $this->tenantContext->companyId())
                ->where('status', 'active')
                ->whereIn('role', ['owner', 'admin'])
                ->whereKeyNot($target->id)
                ->count();

            if ($remainingManagers === 0) {
                throw ApiException::make('LAST_COMPANY_MANAGER_REQUIRED', 'At least one active owner or admin must remain.', 422);
            }
        }
    }

    private function audit(string $action, CompanyUser $companyUser, array $properties, Request $request): void
    {
        $this->auditLogService->logSuccess([
            'event' => $action,
            'action' => $action,
            'module' => 'access',
            'message' => 'Access management change.',
            'record_type' => CompanyUser::class,
            'record_id' => $companyUser->id,
            'record_number' => (string) $companyUser->user_id,
            'metadata' => $properties,
            'user_id' => $request->user()?->id,
        ], tenant: false);
    }

    private function role(int $roleId): Role
    {
        return Role::query()
            ->where(function ($query): void {
                $query->where('is_system', true)->orWhere('company_id', $this->tenantContext->companyId());
            })
            ->where('is_active', true)
            ->whereKey($roleId)
            ->firstOrFail();
    }
}
