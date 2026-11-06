<?php

namespace App\Shared\Subscription;

use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\Subscription;
use App\Shared\Models\TenantDatabase;
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

    public function __construct(
        private readonly PlanOwnerResolver $planOwnerResolver,
    ) {}

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

        // Toko yang memakai Kasir: jatah dari paket POS pemilik. Perusahaan
        // yang memakai keduanya mendapat jatah paket yang lebih besar.
        $products = TenantDatabase::allProducts()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->pluck('product')
            ->all();
        $posLimit = in_array(TenantDatabase::PRODUCT_POS, $products, true) ? $this->posLimitFor($owner) : null;
        $usesAccounting = $products === [] || in_array(TenantDatabase::PRODUCT_ACCOUNTING, $products, true);

        if ($posLimit === null) {
            return $this->limitForOwner($owner);
        }

        $base = $usesAccounting ? max($this->baseLimitFor($owner), $posLimit) : $posLimit;

        return $base + $this->addOnFor($owner);
    }

    /** Jatah user dari paket POS terakhir pemilik; null kalau belum pernah berlangganan POS. */
    private function posLimitFor(User $owner): ?int
    {
        $plan = Subscription::query()
            ->where('user_id', $owner->id)
            ->where('product', TenantDatabase::PRODUCT_POS)
            ->orderByDesc('ends_at')
            ->first()
            ?->plan;

        return $plan ? max(1, (int) $plan->max_users) : null;
    }

    /**
     * Batas untuk tiap perusahaan milik client ini, tanpa perlu menunjuk salah
     * satu perusahaannya. Dipakai area admin, yang menampilkan angka client
     * sebelum perusahaannya tentu sudah ada.
     */
    public function limitForOwner(User $owner): int
    {
        return $this->baseLimitFor($owner) + $this->addOnFor($owner);
    }

    /**
     * Jatah bawaan paket, sebelum add-on.
     */
    private function baseLimitFor(User $owner): int
    {
        $plan = $this->planOwnerResolver->planFor($owner);

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

    /**
     * Add-on dibeli per client dan berlaku di **semua** perusahaannya — bukan
     * satu jatah yang dibagi rata. Client dengan tiga perusahaan dan add-on 5
     * mendapat 5 slot tambahan di masing-masing perusahaan.
     */
    private function addOnFor(User $owner): int
    {
        return max(0, (int) $owner->extra_users);
    }

    public function canAddUser(Company $company): bool
    {
        return $this->usedCount($company) < $this->limitFor($company);
    }

    /**
     * @return array{used:int, limit:int, can_add:bool, plan_name:string|null, extra_users:int}
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
            'extra_users' => $owner ? $this->addOnFor($owner) : 0,
        ];
    }

    private function ownerOf(Company $company): ?User
    {
        return $this->planOwnerResolver->ownerOf($company);
    }
}
