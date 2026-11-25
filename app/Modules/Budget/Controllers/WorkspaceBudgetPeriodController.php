<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Services\BudgetSourceService;
use App\Modules\Workspace\Services\WorkspaceBudgetService;
use App\Shared\Api\ApiResponse;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantConnectionManager;
use App\Shared\Tenant\TenantContext;
use App\Shared\Tenant\TenantProductService;
use Illuminate\Http\JsonResponse;

class WorkspaceBudgetPeriodController extends Controller
{
    use ApiResponse;

    public function index(
        TenantContext $context,
        BudgetSourceService $sources,
        WorkspaceBudgetService $budgets,
        TenantProductService $products,
        TenantConnectionManager $connections,
    ): JsonResponse {
        $periods = $sources->periods($context->companyId())->orderByDesc('period_from')->get(['id', 'name', 'period_from', 'period_to']);
        $totals = null;
        if ($periods->isNotEmpty()) {
            $workspace = $products->find($context->company(), TenantDatabase::PRODUCT_WORKSPACE);
            if ($workspace?->status === 'active') {
                $connections->connect($workspace);
                try {
                    $totals = $budgets->summaryByPeriod();
                } finally {
                    $connections->disconnect('workspace');
                }
            }
        }

        foreach ($periods as $period) {
            $period->limit_minor = $totals === null ? null : (string) ($totals[$period->id]->limit_minor ?? 0);
            $period->committed_minor = $totals === null ? null : (string) ($totals[$period->id]->committed_minor ?? 0);
        }

        return $this->successResponse(['periods' => $periods, 'workspace_url' => config('app.workspace_frontend_url')]);
    }
}
