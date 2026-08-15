<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Requests\StoreBudgetPeriodRequest;
use App\Modules\Budget\Requests\UpdateBudgetPeriodRequest;
use App\Modules\Budget\Services\BudgetPeriodService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class BudgetPeriodController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BudgetPeriodService $service) {}

    public function index(): JsonResponse
    {
        $periods = $this->service->list();

        return $this->successResponse($periods, 'Budget periods retrieved successfully');
    }

    public function store(StoreBudgetPeriodRequest $request): JsonResponse
    {
        $period = $this->service->createWithAllocations($request->validated());

        return $this->successResponse($period, 'Budget period created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $period = $this->service->find($id);

        return $this->successResponse($period, 'Budget period retrieved successfully');
    }

    public function update(UpdateBudgetPeriodRequest $request, int $id): JsonResponse
    {
        $period = $this->service->find($id);
        $period = $this->service->update($period, $request->validated());

        return $this->successResponse($period, 'Budget period updated successfully');
    }

    public function close(int $id): JsonResponse
    {
        $period = $this->service->find($id);
        $period = $this->service->close($period);

        return $this->successResponse($period, 'Budget period closed successfully');
    }
}
