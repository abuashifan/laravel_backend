<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Companies\Services\ProductActivationService;
use App\Modules\Pos\Services\PosSettingService;
use App\Modules\Pos\Services\PosShiftService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Models\Company;
use App\Shared\Models\TenantDatabase;
use App\Shared\Permission\PermissionService;
use App\Shared\Subscription\SubscriptionService;
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
    public function bootstrap(Request $request, PermissionService $permissions, TenantContext $context, PosSettingService $settings, PosShiftService $shifts): JsonResponse
    {
        $user = $this->user($request);
        $keys = array_values(array_filter(
            (array) config('permissions.matrix_keys', []),
            fn (string $key) => str_starts_with($key, 'pos.') && $permissions->can($key),
        ));

        return $this->successResponse([
            'company' => ['id' => $context->company()?->id, 'name' => $context->company()?->name],
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'permissions' => $keys,
            'settings' => $settings->all(),
            'current_shift' => $shifts->current($user),
        ]);
    }
}
