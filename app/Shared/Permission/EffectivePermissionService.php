<?php

namespace App\Shared\Permission;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Models\Role;
use App\Shared\Subscription\PlanPermissionResolver;
use Illuminate\Support\Facades\Schema;

class EffectivePermissionService
{
    public function __construct(
        private readonly PlanPermissionResolver $planPermissionResolver,
    ) {}

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

        $keys = array_values(array_diff(array_unique(array_merge($rolePermissions, $allow)), $deny));

        // Disaring di sini juga supaya daftar yang dikirim ke frontend sudah
        // bersih — guard UI tidak boleh menampilkan tombol yang pasti ditolak.
        return $this->planFilter($companyUser, $keys);
    }

    public function hasPermission(CompanyUser $companyUser, string $permissionKey): bool
    {
        // Lapis 1 — paket. Dievaluasi SEBELUM jalan pintas `*` di bawah, karena
        // paket mengikat semua orang termasuk owner dan admin. Kalau lapis ini
        // hanya dipasang di dalam getEffectivePermissionKeys(), jalan pintas itu
        // membuat owner tembus ke semua fitur — dan owner ada di setiap
        // perusahaan, jadi kebocorannya justru pada orang yang paling sering
        // memakai aplikasinya.
        if (! $this->planAllows($companyUser, $permissionKey)) {
            return false;
        }

        $deny = $this->getUserDenyOverrideKeys($companyUser);
        if (in_array($permissionKey, $deny, true)) {
            return false;
        }

        return in_array($permissionKey, $this->getEffectivePermissionKeys($companyUser), true)
            || in_array('*', $this->rolePermissionsForCompanyUser($companyUser), true);
    }

    public function explainPermission(CompanyUser $companyUser, string $permissionKey): array
    {
        // Sama seperti hasPermission(): lapis paket menang atas segalanya,
        // termasuk allow override dan `*`.
        if (! $this->planAllows($companyUser, $permissionKey)) {
            return ['permission' => $permissionKey, 'allowed' => false, 'source' => 'not_in_plan'];
        }

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

    /**
     * Fitur yang dibuka paket perusahaan ini — dikirim ke frontend berdampingan
     * dengan daftar izin, supaya UI bisa membedakan "minta ke admin perusahaan"
     * dari "naikkan paket".
     *
     * @return list<string>
     */
    public function planFeaturesFor(CompanyUser $companyUser): array
    {
        $company = $this->companyOf($companyUser);

        return $company ? $this->planPermissionResolver->featuresFor($company) : [];
    }

    private function planAllows(CompanyUser $companyUser, string $permissionKey): bool
    {
        $company = $this->companyOf($companyUser);

        // Tanpa perusahaan, paket yang berlaku tidak bisa ditentukan. Dibiarkan
        // lolos: lapis 2 tetap memeriksanya, dan menahan di sini akan mengunci
        // jalur yang selama ini terbuka hanya karena relasinya belum dimuat.
        return $company === null || $this->planPermissionResolver->allows($company, $permissionKey);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function planFilter(CompanyUser $companyUser, array $keys): array
    {
        $company = $this->companyOf($companyUser);

        return $company ? $this->planPermissionResolver->allowedKeysFor($company, $keys) : $keys;
    }

    private function companyOf(CompanyUser $companyUser): ?Company
    {
        $company = $companyUser->company;

        return $company instanceof Company ? $company : null;
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
