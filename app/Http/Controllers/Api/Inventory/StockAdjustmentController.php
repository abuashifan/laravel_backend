<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Requests\Inventory\StockAdjustmentActionRequest;
use App\Http\Requests\Inventory\StoreStockAdjustmentRequest;
use App\Http\Requests\Inventory\UpdateStockAdjustmentRequest;
use App\Http\Requests\Inventory\VoidStockAdjustmentRequest;
use App\Models\Tenant\StockAdjustment;
use App\Services\Inventory\StockAdjustmentService;
use App\Traits\ApiResponse;
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
