<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Services\Inventory\InventoryValuationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryValuationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly InventoryValuationService $service) {}

    public function current(Request $request): JsonResponse
    {
        return $this->successResponse(
            $this->service->currentValuation($request->query()),
            'Inventory valuation retrieved successfully'
        );
    }

    public function asOf(Request $request): JsonResponse
    {
        $asOf = $request->query('as_of_date');
        return $this->successResponse(
            $this->service->valuationAsOf($asOf ? (string) $asOf : null, $request->query()),
            'Inventory valuation retrieved successfully'
        );
    }

    public function byProduct(Request $request, int $productId): JsonResponse
    {
        return $this->successResponse(
            $this->service->valuationByProduct($productId, $request->query()),
            'Inventory valuation retrieved successfully'
        );
    }

    public function byWarehouse(Request $request, int $warehouseId): JsonResponse
    {
        return $this->successResponse(
            $this->service->valuationByWarehouse($warehouseId, $request->query()),
            'Inventory valuation retrieved successfully'
        );
    }
}

