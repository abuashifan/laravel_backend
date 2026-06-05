<?php

namespace App\Http\Controllers\Api\Access;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Access\StoreRoleRequest;
use App\Http\Requests\Access\UpdateRolePermissionsRequest;
use App\Http\Requests\Access\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use App\Services\Audit\AuditLogService;
use App\Services\Tenant\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoleAccessController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function index(): JsonResponse
    {
        $roles = $this->rolesQuery()
            ->withCount(['permissions', 'companyUsers' => fn (Builder $query) => $query
                ->where('company_id', $this->tenantContext->companyId())
                ->where('status', 'active')])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return $this->successResponse($roles, 'Roles retrieved.');
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $role = DB::transaction(function () use ($data): Role {
            $role = Role::query()->create([
                'company_id' => $this->tenantContext->companyId(),
                'name' => $data['name'],
                'slug' => $data['slug'],
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_system' => false,
            ]);

            $this->syncPermissions($role, $data['permission_keys'] ?? []);
            $this->audit('access.role.created', $role, ['permission_keys' => $data['permission_keys'] ?? []]);

            return $role;
        });

        return $this->successResponse($this->rolePayload($role->refresh()), 'Role created.', 201);
    }

    public function show(int $roleId): JsonResponse
    {
        return $this->successResponse($this->rolePayload($this->role($roleId)), 'Role retrieved.');
    }

    public function update(UpdateRoleRequest $request, int $roleId): JsonResponse
    {
        $role = $this->editableRole($roleId);
        $old = $role->only(['name', 'slug', 'description', 'is_active']);
        $role->fill($request->safe()->except(['is_active']))->save();
        $this->audit('access.role.updated', $role, ['old_values' => $old, 'new_values' => $role->only(array_keys($old))]);

        return $this->successResponse($this->rolePayload($role->refresh()), 'Role updated.');
    }

    public function cloneRole(Request $request, int $roleId): JsonResponse
    {
        $source = $this->role($roleId);
        $clone = DB::transaction(function () use ($source, $request): Role {
            $clone = Role::query()->create([
                'company_id' => $this->tenantContext->companyId(),
                'name' => $request->input('name', $source->name.' Copy'),
                'slug' => str($request->input('slug', $source->slug.'-copy-'.time()))->slug()->toString(),
                'description' => $request->input('description', $source->description),
                'is_system' => false,
                'is_active' => true,
            ]);
            $clone->permissions()->sync($source->permissions()->pluck('permissions.id')->all());
            $this->audit('access.role.cloned', $clone, ['source_role_id' => $source->id]);

            return $clone;
        });

        return $this->successResponse($this->rolePayload($clone), 'Role cloned.', 201);
    }

    public function updatePermissions(UpdateRolePermissionsRequest $request, int $roleId): JsonResponse
    {
        $role = $this->editableRole($roleId);
        $oldKeys = $role->permissions()->pluck('permissions.key')->all();
        $newKeys = $request->validated('permission_keys');
        $this->syncPermissions($role, $newKeys);
        $this->audit('access.role.permissions.synced', $role, ['old_permission_keys' => $oldKeys, 'new_permission_keys' => $newKeys]);

        return $this->successResponse($this->rolePayload($role->refresh()), 'Role permissions updated.');
    }

    public function deactivate(int $roleId): JsonResponse
    {
        $role = $this->editableRole($roleId);
        if ($role->companyUsers()->where('company_id', $this->tenantContext->companyId())->where('status', 'active')->exists()) {
            throw ApiException::make('ROLE_ASSIGNED_TO_ACTIVE_USERS', 'Role is assigned to active company users.', 422);
        }

        $role->forceFill(['is_active' => false])->save();
        $this->audit('access.role.deactivated', $role);

        return $this->successResponse($this->rolePayload($role), 'Role deactivated.');
    }

    public function activate(int $roleId): JsonResponse
    {
        $role = $this->editableRole($roleId);
        $role->forceFill(['is_active' => true])->save();
        $this->audit('access.role.reactivated', $role);

        return $this->successResponse($this->rolePayload($role), 'Role reactivated.');
    }

    private function rolesQuery(): Builder
    {
        return Role::query()->where(function (Builder $query): void {
            $query->where('is_system', true)
                ->orWhere('company_id', $this->tenantContext->companyId());
        });
    }

    private function role(int $roleId): Role
    {
        return $this->rolesQuery()->whereKey($roleId)->firstOrFail();
    }

    private function editableRole(int $roleId): Role
    {
        $role = $this->role($roleId);
        if ($role->is_system) {
            throw ApiException::make('SYSTEM_ROLE_READ_ONLY', 'System role presets cannot be changed.', 422);
        }

        return $role;
    }

    private function syncPermissions(Role $role, array $permissionKeys): void
    {
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all();
        $role->permissions()->sync($permissionIds);
    }

    private function rolePayload(Role $role): array
    {
        $role->load('permissions:id,key,label,module,feature,action');
        $assignedCount = $role->companyUsers()
            ->where('company_id', $this->tenantContext->companyId())
            ->where('status', 'active')
            ->count();

        return [
            'id' => $role->id,
            'company_id' => $role->company_id,
            'name' => $role->name,
            'slug' => $role->slug,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_active' => $role->is_active,
            'assigned_users_count' => $assignedCount,
            'permission_keys' => $role->permissions->pluck('key')->values()->all(),
            'permissions' => $role->permissions,
        ];
    }

    private function audit(string $event, Role $role, array $metadata = []): void
    {
        $this->auditLogService->logSuccess([
            'event' => $event,
            'module' => 'access',
            'action' => $event,
            'message' => 'Role access management changed.',
            'record_type' => Role::class,
            'record_id' => $role->id,
            'record_number' => $role->slug,
            'metadata' => $metadata,
        ], tenant: false);
    }
}
