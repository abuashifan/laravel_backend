<?php

namespace App\Modules\Reports\Controllers;

use App\Data\Reports\CashFlowFilter;
use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\CashFlowRequest;
use App\Modules\Reports\Services\CashFlowService;
use App\Support\Api\ApiResponseBuilder;
use App\Shared\Api\ApiResponse;
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

