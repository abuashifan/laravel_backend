<?php

namespace App\Http\Controllers\Api\Budget;

use App\Http\Controllers\Controller;
use App\Services\Budget\BudgetConsolidationService;
use App\Services\Budget\BudgetPeriodService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetConsolidationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BudgetPeriodService $periodService,
        private readonly BudgetConsolidationService $consolidationService,
    ) {}

    public function show(int $periodId, Request $request): JsonResponse
    {
        $period = $this->periodService->find($periodId);
        $result = $this->consolidationService->query($period, $request->query());

        return $this->successResponse($result, 'Budget consolidation retrieved successfully');
    }
}
