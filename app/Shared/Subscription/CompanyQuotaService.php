<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Plan;
use App\Shared\Models\User;

/**
 * Kuota jumlah perusahaan per client.
 *
 * Batasnya dibaca dari paket yang menempel di user (`users.plan_id`), bukan
 * dari tabel subscriptions yang lingkupnya per-perusahaan: keputusan "boleh
 * bikin perusahaan atau tidak" harus bisa diambil justru ketika client belum
 * punya perusahaan sama sekali.
 */
class CompanyQuotaService
{
    /**
     * Paket yang dipakai kalau client belum diberi paket. Sengaja paket
     * terbatas, bukan tanpa batas — kalau owner aplikasi lupa mengaturnya,
     * efeknya menahan, bukan membuka.
     */
    public const DEFAULT_PLAN_CODE = 'free';

    private const FALLBACK_LIMIT = 1;

    /**
     * Hanya perusahaan yang di-owner-i yang dihitung. Client bisa saja
     * diundang sebagai staff di perusahaan client lain; keanggotaan itu tidak
     * boleh memakan jatahnya sendiri.
     */
    public function usedCount(User $user): int
    {
        return $user->companies()
            ->wherePivot('role', 'owner')
            ->wherePivot('status', 'active')
            ->count();
    }

    public function limitFor(User $user): int
    {
        $plan = $user->plan;

        if (! $plan) {
            $plan = Plan::query()->where('code', self::DEFAULT_PLAN_CODE)->first();
        }

        if (! $plan) {
            return self::FALLBACK_LIMIT;
        }

        return max(0, (int) $plan->max_companies);
    }

    public function canCreate(User $user): bool
    {
        return $this->usedCount($user) < $this->limitFor($user);
    }

    /**
     * Ringkasan untuk frontend supaya tombol tambah perusahaan bisa
     * menyesuaikan diri, bukan membiarkan client mengisi form lalu ditolak.
     *
     * @return array{used:int, limit:int, can_create:bool, plan_code:string|null, plan_name:string|null}
     */
    public function summaryFor(User $user): array
    {
        $used = $this->usedCount($user);
        $limit = $this->limitFor($user);
        $plan = $user->plan ?? Plan::query()->where('code', self::DEFAULT_PLAN_CODE)->first();

        return [
            'used' => $used,
            'limit' => $limit,
            'can_create' => $used < $limit,
            'plan_code' => $plan?->code,
            'plan_name' => $plan?->name,
        ];
    }
}
