<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Requests\BudgetComparisonRequest;
use App\Modules\Budget\Services\BudgetComparisonService;
use App\Shared\Api\ApiResponse;
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
