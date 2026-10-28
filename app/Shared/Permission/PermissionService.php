<?php

namespace App\Shared\Permission;

use App\Shared\Tenant\TenantContext;

class PermissionService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EffectivePermissionService $effectivePermissionService,
    ) {}

    public function allPermissions(): array
    {
        return (array) config('permissions.permissions', []);
    }

    public function permissionsForRole(?string $role): array
    {
        if (! $role) {
            return [];
        }

        $roles = (array) config('permissions.roles', []);

        return array_values(array_unique((array) ($roles[$role] ?? [])));
    }

    public function roleHasPermission(?string $role, string $permission): bool
    {
        $rolePermissions = $this->permissionsForRole($role);

        if ($rolePermissions === []) {
            return false;
        }

        if (in_array('*', $rolePermissions, true)) {
            return true;
        }

        return in_array($permission, $rolePermissions, true);
    }

    /** Resolve the current company membership's direct permissions. */
    public function userPermissions(): array
    {
        $companyUser = $this->tenantContext->companyUser();

        return $companyUser
            ? $this->effectivePermissionService->getEffectivePermissionKeys($companyUser)
            : [];
    }

    public function can(string $permission): bool
    {
        $companyUser = $this->tenantContext->companyUser();

        return $companyUser
            ? $this->effectivePermissionService->hasPermission($companyUser, $permission)
            : false;
    }

    public function cannot(string $permission): bool
    {
        return ! $this->can($permission);
    }

    /**
     * Alasan sebuah izin lolos atau ditolak. Dipakai `EnsurePermission` untuk
     * memilih kode error: ditolak paket dan ditolak izin mengarahkan user ke
     * dua orang yang berbeda.
     *
     * @return array{permission:string, allowed:bool, source:string}
     */
    public function explain(string $permission): array
    {
        $companyUser = $this->tenantContext->companyUser();

        if (! $companyUser) {
            return ['permission' => $permission, 'allowed' => false, 'source' => 'no_company'];
        }

        return $this->effectivePermissionService->explainPermission($companyUser, $permission);
    }

    /**
     * Fitur yang dibuka paket perusahaan aktif.
     *
     * @return list<string>
     */
    public function planFeatures(): array
    {
        $companyUser = $this->tenantContext->companyUser();

        return $companyUser
            ? $this->effectivePermissionService->planFeaturesFor($companyUser)
            : [];
    }

    protected function resolveRolePermissions(?string $role): array
    {
        return $this->permissionsForRole($role);
    }
}
