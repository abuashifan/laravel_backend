<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Requests\StoreStockMovementRequest;
use App\Modules\Inventory\Requests\VoidStockMovementRequest;
use App\Modules\Inventory\Services\StockMovementService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockMovementService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Stock movements retrieved successfully');
    }

    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->createDraft($request->validated()), 'Stock movement draft created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Stock movement retrieved successfully');
    }

    public function post(int $id): JsonResponse
    {
        $movement = StockMovement::query()->findOrFail($id);

        return $this->successResponse($this->service->post($movement), 'Stock movement posted successfully');
    }

    public function void(VoidStockMovementRequest $request, int $id): JsonResponse
    {
        $movement = StockMovement::query()->findOrFail($id);

        return $this->successResponse($this->service->void($movement, $request->validated('reason')), 'Stock movement voided successfully');
    }
}
