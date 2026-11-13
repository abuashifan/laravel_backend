<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Services\PosPlanService;
use App\Modules\Pos\Services\PosSecurityService;
use App\Modules\Pos\Services\PosSettingService;
use App\Modules\Pos\Services\PosShiftService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Permission\PermissionService;
use App\Shared\Subscription\PlanOwnerResolver;
use App\Shared\Subscription\SubscriptionService;
use App\Shared\Tenant\ProductActivationService;
use App\Shared\Tenant\TenantContext;
use App\Shared\Tenant\TenantProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosActivationController extends PosController
{
    public function __construct(
        private readonly TenantProductService $products,
        private readonly ProductActivationService $activation,
    ) {}

    /**
     * Status POS untuk perusahaan di header X-Company-ID. Tidak memakai
     * `company.access:pos` karena justru dipanggil sebelum POS aktif.
     */
    public function status(Request $request, SubscriptionService $subscriptions): JsonResponse
    {
        $company = Company::query()->find($request->header('X-Company-ID'));
        $member = $company ? $this->activation->membership($this->user($request), $company) : null;
        if (! $company || ! $member) {
            return $this->errorCodeResponse(ApiErrorCode::COMPANY_ACCESS_DENIED, null, [], 403);
        }

        $owner = $company->created_by ? $company->creator()->first() : null;

        return $this->successResponse([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'active' => $this->products->find($company, TenantDatabase::PRODUCT_POS)?->status === 'active',
            'can_activate' => $this->activation->canActivate($member),
            'subscribed' => $owner !== null && $subscriptions->hasAccess($owner, TenantDatabase::PRODUCT_POS),
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        $company = Company::query()->find($request->header('X-Company-ID'));
        if (! $company) {
            return $this->errorCodeResponse(ApiErrorCode::COMPANY_ACCESS_DENIED, null, [], 403);
        }

        $denied = $this->activation->activate($this->user($request), $company, TenantDatabase::PRODUCT_POS);
        if ($denied) {
            return $denied;
        }

        return $this->successResponse([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'active' => true,
        ], 'POS berhasil diaktifkan.');
    }

    /**
     * Data awal aplikasi kasir: perusahaan, pengguna, izin POS, pengaturan
     * toko, dan shift yang sedang terbuka. Rute ini sudah di dalam
     * `company.access:pos`, jadi tenant POS tersambung.
     */
    public function bootstrap(Request $request, PermissionService $permissions, TenantContext $context, PosSettingService $settings, PosSecurityService $security, PosShiftService $shifts, SubscriptionService $subscriptions, PlanOwnerResolver $owners, PosPlanService $plans): JsonResponse
    {
        $user = $this->user($request);
        // Izin POS + izin mengelola pengguna toko (layar Pengguna toko).
        $keys = array_values(array_filter(
            (array) config('permissions.matrix_keys', []),
            fn (string $key) => (str_starts_with($key, 'pos.') || str_starts_with($key, 'access.users.') || str_starts_with($key, 'access.invitations.')) && $permissions->can($key),
        ));

        // Langganan Kasir milik pemilik toko: spanduk masa coba / habis.
        $company = $context->company();
        $owner = $company ? $owners->ownerOf($company) : null;
        $subscription = $owner ? $subscriptions->summaryFor($owner, TenantDatabase::PRODUCT_POS) : null;
        if ($subscription !== null) {
            $subscription['read_only'] = ! in_array($subscription['state'], [SubscriptionService::STATE_ACTIVE, SubscriptionService::STATE_GRACE], true);
            $subscription['usage'] = $company ? $plans->usage($company) : null;
            // Hanya pemilik yang membayar; kasir tidak diberi tautan berlangganan.
            $subscription['upgrade_url'] = $owner->id === $user->id ? $plans->upgradeUrl($user, $company) : null;
        }

        return $this->successResponse([
            'company' => ['id' => $company?->id, 'name' => $company?->name],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'is_owner' => $owner !== null && $owner->id === $user->id,
            'subscription' => $subscription,
            'permissions' => $keys,
            'settings' => $settings->all() + ['supervisor_pin_set' => $security->pinIsSet() ? '1' : '0'],
            'current_shift' => $shifts->current($user),
        ]);
    }
}
