<?php

namespace App\Services\Permissions;

use App\Models\CompanyUser;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\Schema;

class EffectivePermissionService
{
    public function getRolePermissionKeys(?Role $role): array
    {
        if (! $role) {
            return [];
        }

        return $role->permissions()
            ->pluck('permissions.key')
            ->unique()
            ->values()
            ->all();
    }

    public function getUserAllowOverrideKeys(CompanyUser $companyUser): array
    {
        return $this->overrideKeys($companyUser, 'allow');
    }

    public function getUserDenyOverrideKeys(CompanyUser $companyUser): array
    {
        return $this->overrideKeys($companyUser, 'deny');
    }

    public function getEffectivePermissionKeys(CompanyUser $companyUser): array
    {
        $rolePermissions = $this->rolePermissionsForCompanyUser($companyUser);
        $allow = $this->getUserAllowOverrideKeys($companyUser);
        $deny = $this->getUserDenyOverrideKeys($companyUser);

        if (in_array('*', $rolePermissions, true)) {
            $rolePermissions = array_values(array_unique(array_merge(['*'], $this->allPermissionKeys())));
        }

        return array_values(array_diff(array_unique(array_merge($rolePermissions, $allow)), $deny));
    }

    public function hasPermission(CompanyUser $companyUser, string $permissionKey): bool
    {
        $deny = $this->getUserDenyOverrideKeys($companyUser);
        if (in_array($permissionKey, $deny, true)) {
            return false;
        }

        return in_array($permissionKey, $this->getEffectivePermissionKeys($companyUser), true)
            || in_array('*', $this->rolePermissionsForCompanyUser($companyUser), true);
    }

    public function explainPermission(CompanyUser $companyUser, string $permissionKey): array
    {
        $rolePermissions = $this->rolePermissionsForCompanyUser($companyUser);
        $allow = $this->getUserAllowOverrideKeys($companyUser);
        $deny = $this->getUserDenyOverrideKeys($companyUser);

        if (in_array($permissionKey, $deny, true)) {
            return ['permission' => $permissionKey, 'allowed' => false, 'source' => 'user_override_deny'];
        }

        if (in_array($permissionKey, $allow, true)) {
            return ['permission' => $permissionKey, 'allowed' => true, 'source' => 'user_override_allow'];
        }

        if (in_array('*', $rolePermissions, true) || in_array($permissionKey, $rolePermissions, true)) {
            return ['permission' => $permissionKey, 'allowed' => true, 'source' => 'role_default'];
        }

        return ['permission' => $permissionKey, 'allowed' => false, 'source' => 'not_assigned'];
    }

    public function rolePermissionsForCompanyUser(CompanyUser $companyUser): array
    {
        if ($this->tablesReady() && $companyUser->role_id) {
            $role = $companyUser->rolePreset;
            if ($role instanceof Role) {
                return $this->getRolePermissionKeys($role);
            }
        }

        $roles = (array) config('permissions.roles', []);

        return array_values(array_unique((array) ($roles[$companyUser->role] ?? [])));
    }

    public function allPermissionKeys(): array
    {
        if (Schema::hasTable('permissions')) {
            $keys = Permission::query()->orderBy('sort_order')->pluck('key')->all();
            if ($keys !== []) {
                return $keys;
            }
        }

        return (array) config('permissions.permissions', []);
    }

    private function overrideKeys(CompanyUser $companyUser, string $effect): array
    {
        if (! $this->tablesReady()) {
            return [];
        }

        return $companyUser->permissionOverrides()
            ->where('effect', $effect)
            ->join('permissions', 'permissions.id', '=', 'company_user_permission_overrides.permission_id')
            ->pluck('permissions.key')
            ->unique()
            ->values()
            ->all();
    }

    private function tablesReady(): bool
    {
        return Schema::hasTable('company_user_permission_overrides') && Schema::hasTable('permissions');
    }
}
