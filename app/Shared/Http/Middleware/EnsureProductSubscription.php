<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Subscription\PlanOwnerResolver;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;

/**
 * Mode hanya-baca saat langganan produk pemilik perusahaan habis.
 *
 * Dipasang sesudah `company.access:{product}`. Permintaan baca (GET/HEAD)
 * selalu lolos supaya data tetap bisa dilihat dan tidak ada yang hilang;
 * permintaan tulis ditolak 402 sampai pemilik memperpanjang. Masa tenggang
 * masih dihitung aktif. Perusahaan tanpa pemilik tercatat tidak ditahan.
 */
class EnsureProductSubscription
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PlanOwnerResolver $owners,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function handle(Request $request, Closure $next, string $product): mixed
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $company = $this->context->company();
        $owner = $company ? $this->owners->ownerOf($company) : null;
        if ($owner && ! $this->subscriptions->hasAccess($owner, $product)) {
            return ApiResponseBuilder::error(
                ApiErrorCode::SUBSCRIPTION_INACTIVE,
                'Langganan toko ini sudah habis. Data masih bisa dilihat, tapi transaksi baru tidak bisa disimpan sampai pemilik memperpanjang.',
                [],
                402,
                ['product' => $product, 'state' => $this->subscriptions->stateFor($owner, $product)],
            );
        }

        return $next($request);
    }
}
