<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AnnualClosingGateService;
use App\Services\Accounting\FiscalYearService;
use App\Services\Tenant\TenantContext;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class FiscalYearStatusController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FiscalYearService $fiscalYearService,
        private readonly AnnualClosingGateService $annualClosingGateService,
    ) {}

    public function __invoke(): JsonResponse
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return $this->errorResponse('Active company context not found.', 422);
        }

        $fy = $this->fiscalYearService->getOrCreateActiveFiscalYear($company);

        return $this->successResponse([
            'active_fiscal_year' => [
                'id' => (int) $fy->id,
                'year' => $fy->year,
                'start_date' => $fy->start_date?->toDateString(),
                'end_date' => $fy->end_date?->toDateString(),
                'status' => $fy->status,
                'is_active' => $fy->is_active,
                'is_closed' => (bool) ($fy->is_closed ?? false),
                'locked_until' => $fy->locked_until ? Carbon::parse($fy->locked_until)->toDateString() : null,
                'closed_at' => $fy->closed_at ? Carbon::parse($fy->closed_at)->toDateString() : null,
            ],
            'closing_required' => $this->annualClosingGateService->closingRequired($company),
            'annual_closing_only' => true,
            'monthly_closing_reminder' => false,
        ], 'Fiscal year status retrieved successfully');
    }
}
