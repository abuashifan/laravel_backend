<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosProduct;
use App\Shared\Exceptions\ApiException;
use App\Shared\Models\Company;
use App\Shared\Models\Plan;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use App\Shared\Subscription\PlanOwnerResolver;
use App\Shared\Subscription\SubscriptionService;

/**
 * Batas paket Kasir yang berlaku di dalam toko (jumlah produk) dan jalur
 * upgrade. Paket yang berlaku = paket langganan Kasir milik pemilik toko.
 */
class PosPlanService
{
    public function __construct(
        private readonly PlanOwnerResolver $owners,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function planForCompany(Company $company): ?Plan
    {
        $owner = $this->owners->ownerOf($company);

        return $owner ? $this->subscriptions->planFor($owner, TenantDatabase::PRODUCT_POS) : null;
    }

    /**
     * Produk yang sudah ada tidak pernah dihapus saat turun paket; hanya
     * penambahan baru yang ditahan. Produk nonaktif tetap dihitung.
     */
    public function assertCanAddProduct(Company $company): void
    {
        $limit = $this->planForCompany($company)?->max_products;
        if ($limit === null) {
            return;
        }

        if (PosProduct::query()->count() >= $limit) {
            throw ApiException::make(
                'PRODUCT_QUOTA_EXCEEDED',
                "Paket Kasir ini maksimal {$limit} produk. Hapus produk yang tidak dipakai atau naikkan paket.",
                422,
            );
        }
    }

    /** Pemakaian toko terhadap batas paket, untuk layar Langganan di aplikasi kasir. */
    public function usage(Company $company): array
    {
        return [
            'products' => PosProduct::query()->count(),
            'users' => $company->companyUsers()->where('status', 'active')->count(),
        ];
    }

    /**
     * Pembayaran masih manual: tautan WhatsApp ke penjual (env
     * `POS_SALES_WHATSAPP`, format 628xxx). Null kalau belum diisi.
     */
    public function upgradeUrl(User $user, ?Company $company = null): ?string
    {
        $number = preg_replace('/\D+/', '', (string) config('pos.sales_whatsapp'));
        if ($number === '') {
            return null;
        }

        $text = "Halo, saya ingin berlangganan Finlite Kasir.\nEmail: {$user->email}"
            .($company ? "\nToko: {$company->name}" : '');

        return 'https://wa.me/'.$number.'?text='.rawurlencode($text);
    }
}
