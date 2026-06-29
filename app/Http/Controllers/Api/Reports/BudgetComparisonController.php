<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\BudgetComparisonRequest;
use App\Services\Budget\BudgetComparisonService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class BudgetComparisonController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BudgetComparisonService $service) {}

    public function show(BudgetComparisonRequest $request): JsonResponse
    {
        $result = $this->service->compare($request->validated());

        return $this->successResponse($result, 'Budget comparison retrieved successfully');
    }
}
