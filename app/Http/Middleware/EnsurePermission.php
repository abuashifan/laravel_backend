<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogService;
use App\Services\Permissions\PermissionService;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditEvent;
use App\Support\Api\ApiErrorCode;
use App\Support\Api\ApiResponseBuilder;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class EnsurePermission
{
    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        if ($this->permissionService->cannot($permission)) {
            try {
                $module = str_contains($permission, '.') ? explode('.', $permission, 2)[0] : null;

                $this->auditLogService->logDenied([
                    'event' => AuditEvent::PERMISSION_DENIED,
                    'action' => AuditAction::DENY,
                    'module' => $module,
                    'message' => 'User does not have permission '.$permission.'.',
                    'metadata' => [
                        'permission' => $permission,
                    ],
                ], tenant: true);
            } catch (Throwable $e) {
                // fail-safe: never block main request on audit logging
            }

            return ApiResponseBuilder::error(
                ApiErrorCode::PERMISSION_DENIED,
                null,
                [],
                403,
                ['permission' => $permission]
            );
        }

        return $next($request);
    }
}
