<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
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
     * Paket bawaan tinggal di `PlanOwnerResolver`, satu-satunya tempat rantai
     * "pemilik → paket" ditulis. Alias ini dipertahankan supaya pemanggil lama
     * tidak perlu tahu perpindahannya.
     */
    public const DEFAULT_PLAN_CODE = PlanOwnerResolver::DEFAULT_PLAN_CODE;

    private const FALLBACK_LIMIT = 1;

    public function __construct(
        private readonly PlanOwnerResolver $planOwnerResolver,
        private readonly SubscriptionService $subscriptions,
    ) {}

    /**
     * Kuota untuk produk selain akuntansi (Fase 0 POS): perusahaan milik
     * client yang punya tenant produk itu, dibatasi `max_companies` paket
     * produk tersebut. Tanpa langganan produk, batasnya 0.
     *
     * @return array{used:int, limit:int, can_create:bool, plan_code:string|null, plan_name:string|null, limit_source:string}
     */
    public function summaryForProduct(User $user, string $product): array
    {
        if ($product === TenantDatabase::PRODUCT_ACCOUNTING) {
            return $this->summaryFor($user);
        }

        $ownedCompanyIds = $user->companies()
            ->wherePivot('role', 'owner')
            ->wherePivot('status', 'active')
            ->pluck('companies.id');
        $used = TenantDatabase::forProduct($product)->whereIn('company_id', $ownedCompanyIds)->count();
        $plan = $this->subscriptions->planFor($user, $product);
        $limit = $plan ? max(0, (int) $plan->max_companies) : 0;

        return [
            'used' => $used,
            'limit' => $limit,
            'can_create' => $used < $limit,
            'plan_code' => $plan?->code,
            'plan_name' => $plan?->name,
            'limit_source' => 'plan',
        ];
    }

    /**
     * Hanya perusahaan yang di-owner-i yang dihitung. Client bisa saja
     * diundang sebagai staff di perusahaan client lain; keanggotaan itu tidak
     * boleh memakan jatahnya sendiri.
     */
    public function usedCount(User $user): int
    {
        // Kuota akuntansi: perusahaan yang hanya memakai produk lain (punya
        // tenant, tapi bukan tenant akuntansi) tidak memakan jatah ini.
        return $user->companies()
            ->wherePivot('role', 'owner')
            ->wherePivot('status', 'active')
            ->where(fn ($query) => $query->whereHas('tenantDatabase')->orWhereDoesntHave('productTenants'))
            ->count();
    }

    /**
     * Tier bertingkat (Free/Basic/Pro) memakai angka bawaan paketnya. Hanya
     * tier Custom yang jumlahnya ditentukan per client lewat
     * `users.company_quota` — di situlah owner aplikasi menyepakati angka
     * berapa pun tanpa harus membuat paket baru.
     */
    public function limitFor(User $user): int
    {
        $plan = $this->planFor($user);

        if ($plan?->isCustom()) {
            return $user->company_quota !== null
                ? max(0, (int) $user->company_quota)
                : max(0, (int) $plan->max_companies);
        }

        if (! $plan) {
            return self::FALLBACK_LIMIT;
        }

        return max(0, (int) $plan->max_companies);
    }

    /** Dari mana batas itu berasal — dipakai UI untuk menjelaskan angkanya. */
    public function limitSourceFor(User $user): string
    {
        $plan = $this->planFor($user);

        return $plan?->isCustom() && $user->company_quota !== null ? 'custom' : 'plan';
    }

    private function planFor(User $user): ?Plan
    {
        return $this->planOwnerResolver->planFor($user);
    }

    public function canCreate(User $user): bool
    {
        return $this->usedCount($user) < $this->limitFor($user);
    }

    /**
     * Ringkasan untuk frontend supaya tombol tambah perusahaan bisa
     * menyesuaikan diri, bukan membiarkan client mengisi form lalu ditolak.
     *
     * @return array{used:int, limit:int, can_create:bool, plan_code:string|null, plan_name:string|null, limit_source:string}
     */
    public function summaryFor(User $user): array
    {
        $used = $this->usedCount($user);
        $limit = $this->limitFor($user);
        $plan = $this->planFor($user);

        return [
            'used' => $used,
            'limit' => $limit,
            'can_create' => $used < $limit,
            'plan_code' => $plan?->code,
            'plan_name' => $plan?->name,
            'limit_source' => $this->limitSourceFor($user),
        ];
    }
}
