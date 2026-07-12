<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\CashFlowDirectRequest;
use App\Modules\Reports\Services\CashFlowDirectService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class CashFlowDirectController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashFlowDirectService $service) {}

    public function index(CashFlowDirectRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->getReport($request->validated()), 'Direct cash flow statement retrieved successfully');
    }
}
