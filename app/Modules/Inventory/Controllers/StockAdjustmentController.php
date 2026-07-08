<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Requests\StockAdjustmentActionRequest;
use App\Modules\Inventory\Requests\StoreStockAdjustmentRequest;
use App\Modules\Inventory\Requests\UpdateStockAdjustmentRequest;
use App\Modules\Inventory\Requests\VoidStockAdjustmentRequest;
use App\Models\Tenant\StockAdjustment;
use App\Modules\Inventory\Services\StockAdjustmentService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAdjustmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockAdjustmentService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Stock adjustments retrieved successfully');
    }

    public function store(StoreStockAdjustmentRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Stock adjustment created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Stock adjustment retrieved successfully');
    }

    public function update(UpdateStockAdjustmentRequest $request, int $id): JsonResponse
    {
        $adj = StockAdjustment::query()->findOrFail($id);
        return $this->successResponse($this->service->update($adj, $request->validated()), 'Stock adjustment updated successfully');
    }

    public function approve(StockAdjustmentActionRequest $request, int $id): JsonResponse
    {
        $adj = StockAdjustment::query()->findOrFail($id);
        return $this->successResponse($this->service->approve($adj), 'Stock adjustment approved successfully');
    }

    public function post(StockAdjustmentActionRequest $request, int $id): JsonResponse
    {
        $adj = StockAdjustment::query()->findOrFail($id);
        return $this->successResponse($this->service->post($adj), 'Stock adjustment posted successfully');
    }

    public function void(VoidStockAdjustmentRequest $request, int $id): JsonResponse
    {
        $adj = StockAdjustment::query()->findOrFail($id);
        $request->validated();
        return $this->successResponse($this->service->void($adj, $request->input('reason')), 'Stock adjustment voided successfully');
    }
}
