<?php

namespace App\Http\Controllers\Api\Access;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Role;
use App\Services\Tenant\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccessAuditController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $query = ActivityLog::query()
            ->with('user:id,name,email')
            ->where('company_id', $tenantContext->companyId())
            ->where('module', 'access')
            ->latest();

        if ($request->filled('user_id')) {
            $query->where('user_id', (int) $request->input('user_id'));
        }
        if ($request->filled('role_id')) {
            $query->where('subject_type', Role::class)
                ->where('subject_id', (string) $request->input('role_id'));
        }
        if ($request->filled('action')) {
            $query->where('action', 'like', '%'.$request->input('action').'%');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->input('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->input('date_to'));
        }

        return $this->successResponse($query->limit(200)->get(), 'Access audit retrieved.');
    }
}
