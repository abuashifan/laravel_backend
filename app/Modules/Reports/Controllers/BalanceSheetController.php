<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\BalanceSheetRequest;
use App\Modules\Reports\Services\BalanceSheetService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ApiResponseBuilder;
use App\Shared\Reports\Data\BalanceSheetFilter;
use Illuminate\Http\JsonResponse;

class BalanceSheetController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BalanceSheetService $service) {}

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
