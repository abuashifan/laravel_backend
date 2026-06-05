<?php

namespace App\Http\Controllers\Api\Reports;

use App\Data\Reports\FinancialSummaryFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\FinancialSummaryRequest;
use App\Services\Reports\FinancialSummaryService;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class FinancialSummaryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FinancialSummaryService $service)
    {
    }

    public function index(FinancialSummaryRequest $request): JsonResponse
    {
        $filter = FinancialSummaryFilter::fromArray($request->validated());

        $result = $this->service->getSummary($filter);
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Invalid financial summary filter.', [
                'filter' => $filter->toArray(),
            ]);
        }

        return $this->successResponse($result, 'Financial summary retrieved successfully');
    }
}

