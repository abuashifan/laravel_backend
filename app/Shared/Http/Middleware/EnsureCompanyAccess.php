<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Auth\TokenAbility;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class EnsureCompanyAccess
{
    public function __construct(private readonly TenantConnectionManager $connectionManager) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response|RedirectResponse)  $next
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponseBuilder::error(ApiErrorCode::UNAUTHENTICATED, null, [], 401);
        }

        // Sesi admin aplikasi tidak boleh membuka data perusahaan. Token client
        // membawa ability `client`; token admin tidak, jadi tertahan di sini.
        // Token lama yang terbit sebelum pemisahan ini berability `*` dan tetap
        // lolos, sehingga sesi client yang sedang berjalan tidak terputus.
        if (! $user->tokenCan(TokenAbility::CLIENT)) {
            return ApiResponseBuilder::error(
                ApiErrorCode::FORBIDDEN,
                'Sesi ini tidak berhak membuka data perusahaan.',
                [],
                403
            );
        }

        $companyId = $request->header('X-Company-ID');

        if (! $companyId) {
            return ApiResponseBuilder::error(ApiErrorCode::X_COMPANY_ID_REQUIRED, null, [], 422);
        }

        $company = Company::find($companyId);

        if (! $company) {
            return ApiResponseBuilder::error(ApiErrorCode::COMPANY_NOT_FOUND, null, [], 404);
        }

        $companyUser = CompanyUser::query()
            ->where('company_id', $company->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $companyUser) {
            return ApiResponseBuilder::error(ApiErrorCode::COMPANY_ACCESS_DENIED, null, [], 403);
        }

        $tenantDatabase = TenantDatabase::query()
            ->where('company_id', $company->id)
            ->where('status', 'active')
            ->first();

        if (! $tenantDatabase) {
            return ApiResponseBuilder::error(ApiErrorCode::TENANT_DATABASE_NOT_ACTIVE, null, [], 422);
        }

        app(TenantContext::class)->set($company, $companyUser, $tenantDatabase);

        $request->attributes->set('active_company', $company);
        $request->attributes->set('active_company_user', $companyUser);
        $request->attributes->set('active_tenant_database', $tenantDatabase);

        try {
            $this->connectionManager->connect($tenantDatabase);
        } catch (Throwable $e) {
            return ApiResponseBuilder::error(
                ApiErrorCode::TENANT_DATABASE_NOT_ACTIVE,
                'Tenant database is not available.',
                [],
                422,
                [
                    'company_id' => $company->id,
                    'database_name' => $tenantDatabase->database_name,
                    'database_path' => $tenantDatabase->database_path,
                    'detail' => $e->getMessage(),
                ],
            );
        }

        return $next($request);
    }
}
