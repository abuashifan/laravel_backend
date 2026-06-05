<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\StockBalanceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly StockBalanceService $service) {}

    public function index(Request $request): JsonResponse
    {
        $balances = $this->service->list($request->query())->get();
        return $this->successResponse($balances, 'Stock balances retrieved successfully');
    }

    public function byProduct(Request $request, int $productId): JsonResponse
    {
        $balances = $this->service->list(array_merge($request->query(), ['product_id' => $productId]))->get();
        return $this->successResponse($balances, 'Stock balances retrieved successfully');
    }

    public function byWarehouse(Request $request, int $warehouseId): JsonResponse
    {
        $balances = $this->service->list(array_merge($request->query(), ['warehouse_id' => $warehouseId]))->get();
        return $this->successResponse($balances, 'Stock balances retrieved successfully');
    }
}

