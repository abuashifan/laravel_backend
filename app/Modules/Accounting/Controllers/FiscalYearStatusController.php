<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AnnualClosingGateService;
use App\Modules\Accounting\Services\FiscalYearService;
use App\Shared\Api\ApiResponse;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;

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
                'id' => $fy->id,
                'year' => $fy->year,
                'start_date' => $fy->start_date?->toDateString(),
                'end_date' => $fy->end_date?->toDateString(),
                'status' => $fy->status,
                'is_active' => $fy->is_active,
            ],
            'closing_required' => $this->annualClosingGateService->closingRequired($company),
            'annual_closing_only' => true,
            'monthly_closing_reminder' => false,
        ], 'Fiscal year status retrieved successfully');
    }
}
