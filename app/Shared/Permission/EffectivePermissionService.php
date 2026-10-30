<?php

namespace App\Shared\Permission;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Permission;
use App\Shared\Subscription\PlanPermissionResolver;
use Illuminate\Support\Facades\Schema;

class EffectivePermissionService
{
    public function __construct(
        private readonly PlanPermissionResolver $planPermissionResolver,
    ) {}

    public function getEffectivePermissionKeys(CompanyUser $companyUser): array
    {
        $keys = $this->hasFullAccess($companyUser)
            ? $this->allPermissionKeys()
            : $companyUser->assignedPermissions()->pluck('permissions.key')->unique()->values()->all();

        // Disaring di sini juga supaya daftar yang dikirim ke frontend sudah
        // bersih — guard UI tidak boleh menampilkan tombol yang pasti ditolak.
        return $this->planFilter($companyUser, $keys);
    }

    public function hasPermission(CompanyUser $companyUser, string $permissionKey): bool
    {
        if (! $this->planAllows($companyUser, $permissionKey)) {
            return false;
        }

        return $this->hasFullAccess($companyUser)
            || $companyUser->assignedPermissions()->where('permissions.key', $permissionKey)->exists();
    }

    public function explainPermission(CompanyUser $companyUser, string $permissionKey): array
    {
        if (! $this->planAllows($companyUser, $permissionKey)) {
            return ['permission' => $permissionKey, 'allowed' => false, 'source' => 'not_in_plan'];
        }

        if ($this->hasFullAccess($companyUser)) {
            return ['permission' => $permissionKey, 'allowed' => true, 'source' => 'full_access'];
        }

        if ($companyUser->assignedPermissions()->where('permissions.key', $permissionKey)->exists()) {
            return ['permission' => $permissionKey, 'allowed' => true, 'source' => 'direct'];
        }

        return ['permission' => $permissionKey, 'allowed' => false, 'source' => 'not_assigned'];
    }

    public function allPermissionKeys(): array
    {
        $configured = (array) config('permissions.permissions', []);
        if (Schema::hasTable('permissions')) {
            $keys = Permission::query()->orderBy('sort_order')->pluck('key')->all();

            return array_values(array_unique(array_merge($configured, $keys)));
        }

        return $configured;
    }

    private function hasFullAccess(CompanyUser $companyUser): bool
    {
        return $companyUser->full_access || $companyUser->is_owner;
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
}
