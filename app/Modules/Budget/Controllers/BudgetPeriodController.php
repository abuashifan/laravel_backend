<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Requests\StoreBudgetPeriodRequest;
use App\Modules\Budget\Requests\UpdateBudgetPeriodRequest;
use App\Modules\Budget\Services\BudgetPeriodService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetPeriodController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BudgetPeriodService $service) {}

    /**
     * `listResponse()` (bukan `successResponse()`) supaya bentuk balasannya
     * mengikuti kontrak daftar yang sama dengan modul lain: terpaginasi beserta
     * `meta.total` saat request membawa `page`/`per_page`, dan koleksi utuh saat
     * tidak — yang dipakai dropdown pemilih pagu.
     */
    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Budget periods retrieved successfully');
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
