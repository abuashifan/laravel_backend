<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Requests\BudgetCashRequest;
use App\Modules\Budget\Services\BudgetCashService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class BudgetCashController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BudgetCashService $service) {}

    public function show(BudgetCashRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->service->summary($request->validated()),
            'Cash budget retrieved successfully',
        );
    }
}
