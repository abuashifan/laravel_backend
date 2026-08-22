<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\TrialBalanceRequest;
use App\Modules\Reports\Services\TrialBalanceService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Reports\Data\TrialBalanceFilter;
use Illuminate\Http\JsonResponse;

class TrialBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly TrialBalanceService $service) {}

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
