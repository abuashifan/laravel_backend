<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditLogService;
use App\Shared\Permission\PermissionService;
use App\Shared\Subscription\UpgradeLinkBuilder;
use App\Shared\Tenant\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class EnsurePermission
{
    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly AuditLogService $auditLogService,
        private readonly TenantContext $tenantContext,
        private readonly UpgradeLinkBuilder $upgradeLinkBuilder,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $permissions = array_values(array_filter(explode('|', $permission)));
        $allowed = collect($permissions)->contains(fn (string $item): bool => $this->permissionService->can($item));

        if (! $allowed) {
            $errorCode = $this->errorCodeFor($permissions);

            try {
                $module = str_contains($permission, '.') ? explode('.', $permission, 2)[0] : null;

                $this->auditLogService->logDenied([
                    'event' => AuditEvent::PERMISSION_DENIED,
                    'action' => AuditAction::DENY,
                    'module' => $module,
                    'message' => 'User does not have permission '.$permission.'.',
                    'metadata' => [
                        'permission' => $permission,
                        'permissions' => $permissions,
                        'reason' => $errorCode,
                    ],
                ], tenant: true);
            } catch (Throwable $e) {
                // fail-safe: never block main request on audit logging
            }

            $meta = ['permission' => $permission];

            if ($errorCode === ApiErrorCode::FEATURE_NOT_IN_PLAN) {
                $company = $this->tenantContext->company();
                $meta['upgrade_url'] = $company
                    ? $this->upgradeLinkBuilder->buildFor($company, $permission)
                    : null;
            }

            return ApiResponseBuilder::error(
                $errorCode,
                null,
                [],
                403,
                $meta
            );
        }

        return $next($request);
    }

    /**
     * `FEATURE_NOT_IN_PLAN` hanya kalau **semua** izin alternatif tertutup
     * paket. Kalau satu saja di antaranya dibuka paket tapi user tidak
     * memilikinya, yang kurang adalah izinnya — dan menyuruh dia menaikkan
     * paket akan mengirimnya ke orang yang salah.
     *
     * @param  list<string>  $permissions
     */
    private function errorCodeFor(array $permissions): string
    {
        if ($permissions === []) {
            return ApiErrorCode::PERMISSION_DENIED;
        }

        foreach ($permissions as $item) {
            if (($this->permissionService->explain($item)['source'] ?? null) !== 'not_in_plan') {
                return ApiErrorCode::PERMISSION_DENIED;
            }
        }

        return ApiErrorCode::FEATURE_NOT_IN_PLAN;
    }
}
