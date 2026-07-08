<?php

namespace App\Shared\Http\Middleware;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Audit\AuditAction;
use App\Shared\Audit\AuditEvent;
use App\Shared\Audit\AuditLogService;
use App\Shared\Permission\PermissionService;
use Closure;
use Illuminate\Http\Request;
use Throwable;

class EnsurePermission
{
    public function __construct(
        private readonly PermissionService $permissionService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function handle(Request $request, Closure $next, string $permission): mixed
    {
        $permissions = array_values(array_filter(explode('|', $permission)));
        $allowed = collect($permissions)->contains(fn (string $item): bool => $this->permissionService->can($item));

        if (! $allowed) {
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
