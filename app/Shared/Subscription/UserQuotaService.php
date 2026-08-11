<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Plan;
use App\Shared\Models\User;

/**
 * Batas jumlah user aktif di sebuah perusahaan.
 *
 * Batasnya berlaku **per perusahaan**, diambil dari paket pemilik perusahaan
 * itu: client Pro dengan tiga perusahaan boleh mengisi masing-masing sampai
 * batas paketnya. Paket menempel di client (`users.plan_id`), sedangkan
 * keanggotaan menempel di perusahaan — service ini yang menjembatani keduanya.
 */
class UserQuotaService
{
    private const FALLBACK_LIMIT = 1;

    /**
     * Owner ikut dihitung. Paket Free dengan `max_users = 1` berarti pemilik
     * bekerja sendirian; kalau owner dikecualikan, angka di paket menyesatkan.
     */
    public function usedCount(Company $company): int
    {
        return CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->count();
    }

    public function limitFor(Company $company): int
    {
        $owner = $this->ownerOf($company);

        if (! $owner) {
            return self::FALLBACK_LIMIT;
        }

        $plan = $owner->plan ?? Plan::query()->where('code', CompanyQuotaService::DEFAULT_PLAN_CODE)->first();

        if ($plan?->isCustom()) {
            return $owner->user_quota !== null
                ? max(1, (int) $owner->user_quota)
                : max(1, (int) $plan->max_users);
        }

        if (! $plan) {
            return self::FALLBACK_LIMIT;
        }

        return max(1, (int) $plan->max_users);
    }

    public function canAddUser(Company $company): bool
    {
        return $this->usedCount($company) < $this->limitFor($company);
    }

    /**
     * @return array{used:int, limit:int, can_add:bool, plan_name:string|null}
     */
    public function summaryFor(Company $company): array
    {
        $owner = $this->ownerOf($company);
        $used = $this->usedCount($company);
        $limit = $this->limitFor($company);

        return [
            'used' => $used,
            'limit' => $limit,
            'can_add' => $used < $limit,
            'plan_name' => $owner?->plan?->name,
        ];
    }

    /**
     * Pemilik dibaca dari `companies.created_by`, bukan dari baris ber-role
     * owner di `company_users`: baris owner bisa lebih dari satu kalau ada
     * owner kedua yang di-assign, dan paket siapa yang berlaku jadi ambigu.
     */
    private function ownerOf(Company $company): ?User
    {
        if (! $company->created_by) {
            return null;
        }

        return User::query()->with('plan')->find($company->created_by);
    }
}
