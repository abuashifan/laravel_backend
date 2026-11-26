<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Budget\BudgetSourceService;
use App\Shared\Budget\WorkspaceBudgetSummaryReader;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;

class WorkspaceBudgetPeriodController extends Controller
{
    use ApiResponse;

    public function index(TenantContext $context, BudgetSourceService $sources, WorkspaceBudgetSummaryReader $summary): JsonResponse
    {
        $periods = $sources->periods($context->companyId())->orderByDesc('period_from')->get(['id', 'name', 'period_from', 'period_to']);
        $totals = $periods->isNotEmpty() ? $summary->totalsByPeriod($context->company()) : null;

        foreach ($periods as $period) {
            $period->limit_minor = $totals === null ? null : ($totals[$period->id]['limit_minor'] ?? '0');
            $period->committed_minor = $totals === null ? null : ($totals[$period->id]['committed_minor'] ?? '0');
        }

        return $this->successResponse(['periods' => $periods, 'workspace_url' => config('app.workspace_frontend_url')]);
    }
}
