<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\EquityChangesRequest;
use App\Modules\Reports\Services\EquityChangesService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class EquityChangesController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly EquityChangesService $service) {}

    public function index(EquityChangesRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->getReport($request->validated()), 'Equity changes report retrieved successfully');
    }
}
