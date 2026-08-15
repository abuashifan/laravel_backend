<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Requests\StoreBudgetAllocationRequest;
use App\Modules\Budget\Requests\UpdateBudgetAllocationRequest;
use App\Modules\Budget\Services\BudgetAllocationService;
use App\Modules\Budget\Services\BudgetPeriodService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class BudgetAllocationController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BudgetAllocationService $service,
        private readonly BudgetPeriodService $periodService,
    ) {}

    public function index(int $periodId): JsonResponse
    {
        $period = $this->periodService->find($periodId);

        return $this->successResponse($this->service->list($period), 'Budget allocations retrieved successfully');
    }

    public function store(StoreBudgetAllocationRequest $request, int $periodId): JsonResponse
    {
        $period = $this->periodService->find($periodId);
        $allocation = $this->service->create($period, $request->validated());

        return $this->successResponse($allocation, 'Budget allocation created successfully', 201);
    }

    public function update(UpdateBudgetAllocationRequest $request, int $id): JsonResponse
    {
        $allocation = $this->service->find($id);
        $allocation = $this->service->update($allocation, $request->validated());

        return $this->successResponse($allocation, 'Budget allocation updated successfully');
    }

    public function show(int $id): JsonResponse
    {
        $allocation = $this->service->find($id)->load('department:id,code,name');

        return $this->successResponse($allocation, 'Budget allocation retrieved successfully');
    }
}
