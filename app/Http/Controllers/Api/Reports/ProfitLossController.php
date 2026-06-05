<?php

namespace App\Http\Controllers\Api\Reports;

use App\Data\Reports\ProfitLossFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ProfitLossRequest;
use App\Services\Reports\ProfitLossService;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProfitLossController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProfitLossService $service)
    {
    }

    public function index(ProfitLossRequest $request): JsonResponse
    {
        $filter = ProfitLossFilter::fromArray($request->validated());

        $result = $this->service->getProfitLoss($filter);
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Invalid profit and loss filter.', [
                'filter' => $filter->toArray(),
            ]);
        }

        return $this->successResponse($result, 'Profit and loss statement retrieved successfully');
    }
}

