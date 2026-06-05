<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\UpdatePeriodLockRequest;
use App\Models\FiscalYear;
use App\Services\Accounting\FiscalYearService;
use App\Services\Audit\AuditLogService;
use App\Services\Tenant\TenantContext;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class PeriodLockController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FiscalYearService $fiscalYearService,
        private readonly AuditLogService $auditLogService,
    ) {
    }

    public function status(): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }

        $fy = $this->fiscalYearService->getOrCreateActiveFiscalYear($company);

        return $this->successResponse([
            'active_fiscal_year' => [
                'id' => (int) $fy->id,
                'year' => (int) $fy->year,
                'start_date' => $fy->start_date?->toDateString(),
                'end_date' => $fy->end_date?->toDateString(),
                'status' => (string) $fy->status,
                'is_active' => (bool) $fy->is_active,
                'is_closed' => (bool) ($fy->is_closed ?? false),
                'locked_until' => $fy->locked_until ? Carbon::parse($fy->locked_until)->toDateString() : null,
            ],
        ], 'Period lock status retrieved successfully');
    }

    public function update(UpdatePeriodLockRequest $request): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }

        $fy = $this->fiscalYearService->getOrCreateActiveFiscalYear($company);

        $lockUntil = $request->validated('lock_until');
        $overrideReason = $request->validated('override_reason');

        if ($lockUntil !== null) {
            $lockUntil = Carbon::parse((string) $lockUntil)->toDateString();
        }

        $fy->forceFill([
            'locked_until' => $lockUntil,
        ])->save();

        $this->auditLogService->logSuccess([
            'event' => 'fiscal_year.lock_updated',
            'module' => 'accounting',
            'action' => 'fiscal_year.lock_updated',
            'message' => 'Fiscal year lock updated.',
            'metadata' => [
                'fiscal_year_id' => (int) $fy->id,
                'locked_until' => $lockUntil,
                'override_reason' => $overrideReason,
            ],
        ], tenant: true);

        return $this->successResponse([
            'fiscal_year_id' => (int) $fy->id,
            'locked_until' => $lockUntil,
        ], 'Period lock updated successfully');
    }
}

