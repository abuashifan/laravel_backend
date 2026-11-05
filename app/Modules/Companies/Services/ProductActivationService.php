<?php

namespace App\Modules\Companies\Services;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\User;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Subscription\UpgradeLinkBuilder;
use App\Shared\Tenant\TenantProductService;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Aturan tunggal "siapa boleh mengaktifkan produk apa" untuk
 * `POST /companies/{id}/products/{product}/activate` dan `POST /pos/activate`.
 *
 * - Produk dikenal (config `tenant.products`).
 * - Pemanggil anggota aktif perusahaan, pemilik atau akses penuh.
 * - Pemilik perusahaan (`companies.created_by`) berlangganan produk itu,
 *   aktif atau dalam masa tenggang.
 */
class ProductActivationService
{
    public function __construct(
        private readonly TenantProductService $products,
        private readonly SubscriptionService $subscriptions,
        private readonly UpgradeLinkBuilder $upgradeLinks,
    ) {}

    /**
     * Mengembalikan response error bila ditolak, atau null bila produk sudah
     * aktif setelah pemanggilan ini.
     */
    public function activate(User $actor, Company $company, string $product): ?JsonResponse
    {
        if (! in_array($product, TenantProductService::products(), true)) {
            return ApiResponseBuilder::error(ApiErrorCode::UNKNOWN_ERROR, 'Produk tidak dikenal.', [], 404);
        }

        $member = $this->membership($actor, $company);
        if (! $member) {
            return ApiResponseBuilder::error(ApiErrorCode::COMPANY_ACCESS_DENIED, null, [], 403);
        }
        if (! $member->is_owner && ! $member->full_access) {
            return ApiResponseBuilder::error(ApiErrorCode::FORBIDDEN, 'Hanya pemilik perusahaan yang bisa mengaktifkan produk.', [], 403);
        }

        if ($this->products->find($company, $product)?->status === 'active') {
            return null;
        }

        $owner = $company->created_by ? User::query()->find($company->created_by) : null;
        if (! $owner || ! $this->subscriptions->hasAccess($owner, $product)) {
            return ApiResponseBuilder::error(
                ApiErrorCode::SUBSCRIPTION_REQUIRED,
                'Perusahaan ini belum berlangganan produk tersebut.',
                [],
                403,
                ['product' => $product, 'renewal_url' => $owner ? $this->upgradeLinks->renewalLinkFor($owner) : null],
            );
        }

        try {
            $this->products->activate($company, $product);
        } catch (Throwable $e) {
            report($e);

            return ApiResponseBuilder::error(
                ApiErrorCode::UNKNOWN_ERROR,
                config('app.debug') ? 'Produk gagal diaktifkan: '.$e->getMessage() : 'Produk gagal diaktifkan. Coba lagi.',
                [],
                500,
            );
        }

        return null;
    }

    public function membership(User $actor, Company $company): ?CompanyUser
    {
        return CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->first();
    }

    public function canActivate(?CompanyUser $member): bool
    {
        return (bool) ($member?->is_owner || $member?->full_access);
    }
}
