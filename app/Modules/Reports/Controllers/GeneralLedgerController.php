<?php

namespace App\Modules\Reports\Controllers;

use App\Data\Reports\LedgerFilter;
use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\GeneralLedgerRequest;
use App\Modules\Reports\Services\GeneralLedgerQueryService;
use App\Support\Api\ApiResponseBuilder;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class GeneralLedgerController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly GeneralLedgerQueryService $service)
    {
    }

    public function index(GeneralLedgerRequest $request): JsonResponse
    {
        $filter = LedgerFilter::fromArray($request->validated());

        $result = $this->service->getLedger($filter);
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Invalid ledger filter.', [
                'filter' => $filter->toArray(),
            ]);
        }

        return $this->successResponse($result, 'General ledger retrieved successfully');
    }
}
