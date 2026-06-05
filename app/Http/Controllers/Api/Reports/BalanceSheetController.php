<?php

namespace App\Http\Controllers\Api\Reports;

use App\Data\Reports\BalanceSheetFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\BalanceSheetRequest;
use App\Services\Reports\BalanceSheetService;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class BalanceSheetController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BalanceSheetService $service)
    {
    }

    public function index(BalanceSheetRequest $request): JsonResponse
    {
        $filter = BalanceSheetFilter::fromArray($request->validated());

        $result = $this->service->getBalanceSheet($filter);
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Invalid balance sheet filter.', [
                'filter' => $filter->toArray(),
            ]);
        }

        return $this->successResponse($result, 'Balance sheet retrieved successfully');
    }
}

