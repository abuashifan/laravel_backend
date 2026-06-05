<?php

namespace App\Http\Controllers\Api\Reports;

use App\Data\Reports\TrialBalanceFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\TrialBalanceRequest;
use App\Services\Reports\TrialBalanceService;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class TrialBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TrialBalanceService $service)
    {
    }

    public function index(TrialBalanceRequest $request): JsonResponse
    {
        $filter = TrialBalanceFilter::fromArray($request->validated());

        $result = $this->service->getTrialBalance($filter);
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Invalid trial balance filter.', [
                'filter' => $filter->toArray(),
            ]);
        }

        return $this->successResponse($result, 'Trial balance retrieved successfully');
    }
}

