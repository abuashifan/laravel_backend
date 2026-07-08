<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\ProfitLossRequest;
use App\Modules\Reports\Services\ProfitLossService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Reports\Data\ProfitLossFilter;
use Illuminate\Http\JsonResponse;

class ProfitLossController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProfitLossService $service) {}

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
