<?php

namespace App\Modules\Pos\Controllers;

use App\Modules\Pos\Services\PosActivationService;
use App\Modules\Pos\Services\PosSettingService;
use App\Modules\Pos\Services\PosShiftService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Permission\PermissionService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PosActivationController extends PosController
{
    public function __construct(private readonly PosActivationService $activation) {}

    /**
     * Status POS untuk perusahaan di header X-Company-ID. Tidak memakai
     * `company.access:pos` karena justru dipanggil sebelum POS aktif.
     */
    public function status(Request $request): JsonResponse
    {
        [$company, $member] = $this->membership($request);
        if (! $company) {
            return $this->errorCodeResponse(ApiErrorCode::COMPANY_ACCESS_DENIED, null, [], 403);
        }

        $tenant = $this->activation->find($company);

        return $this->successResponse([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'active' => $tenant?->status === 'active',
            'can_activate' => (bool) ($member->is_owner || $member->full_access),
        ]);
    }

    public function activate(Request $request): JsonResponse
    {
        [$company, $member] = $this->membership($request);
        if (! $company) {
            return $this->errorCodeResponse(ApiErrorCode::COMPANY_ACCESS_DENIED, null, [], 403);
        }
        if (! $member->is_owner && ! $member->full_access) {
            return $this->errorCodeResponse(ApiErrorCode::FORBIDDEN, 'Hanya pemilik perusahaan yang bisa mengaktifkan POS.', [], 403);
        }

        try {
            $tenant = $this->activation->activate($company);
        } catch (Throwable $e) {
            report($e);

            return $this->errorResponse('POS gagal diaktifkan: '.$e->getMessage(), 500);
        }

        return $this->successResponse([
            'company' => ['id' => $company->id, 'name' => $company->name],
            'active' => $tenant->status === 'active',
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

    /** @return array{0: ?Company, 1: ?CompanyUser} */
    private function membership(Request $request): array
    {
        $company = Company::query()->find($request->header('X-Company-ID'));
        if (! $company) {
            return [null, null];
        }

        $member = CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $request->user()?->id)
            ->where('status', 'active')
            ->first();

        return $member ? [$company, $member] : [null, null];
    }
}
