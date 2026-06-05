<?php

namespace App\Http\Controllers\Api\Reports;

use App\Data\Reports\CashFlowFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\CashFlowRequest;
use App\Services\Reports\CashFlowService;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class CashFlowController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashFlowService $service)
    {
    }

    public function index(CashFlowRequest $request): JsonResponse
    {
        $filter = CashFlowFilter::fromArray($request->validated());

        $result = $this->service->getCashFlow($filter);
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Invalid cash flow filter.', [
                'filter' => $filter->toArray(),
            ]);
        }

        return $this->successResponse($result, 'Cash flow statement retrieved successfully');
    }
}

