<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Services\Reports\InventoryAlertReportService;
use App\Modules\Inventory\Services\Reports\InventoryValuationReportService;
use App\Modules\Inventory\Services\Reports\StockBalanceReportService;
use App\Modules\Inventory\Services\Reports\StockCardReportService;
use App\Modules\Inventory\Services\Reports\StockMovementReportService;
use App\Shared\Api\ApiResponse;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly StockBalanceReportService $stockBalanceReport,
        private readonly StockMovementReportService $stockMovementReport,
        private readonly StockCardReportService $stockCardReport,
        private readonly InventoryValuationReportService $valuationReport,
        private readonly InventoryAlertReportService $alertReport,
    ) {}

    public function stockBalances(Request $request): JsonResponse
    {
        return $this->successResponse($this->stockBalanceReport->report($request->query()), 'Stock balance report retrieved successfully');
    }

    public function stockMovements(Request $request): JsonResponse
    {
        return $this->successResponse($this->stockMovementReport->report($request->query()), 'Stock movement report retrieved successfully');
    }

    public function stockCard(Request $request): JsonResponse
    {
        $productId = $request->query('product_id') !== null ? (int) $request->query('product_id') : null;
        $categoryId = $request->query('category_id') !== null ? (int) $request->query('category_id') : null;
        if (($productId ?? 0) <= 0 && ($categoryId ?? 0) <= 0) {
            throw ApiException::make('VALIDATION_ERROR', 'product_id or category_id is required.', 422, [
                'product_id' => ['The product_id or category_id field is required.'],
            ]);
        }

        $warehouseId = $request->query('warehouse_id') !== null ? (int) $request->query('warehouse_id') : null;

        return $this->successResponse(
            $this->stockCardReport->card($productId, $warehouseId, $request->query()),
            'Stock card report retrieved successfully'
        );
    }

    public function valuation(Request $request): JsonResponse
    {
        $asOf = $request->query('as_of_date');
        $data = $asOf
            ? $this->valuationReport->asOf((string) $asOf, $request->query())
            : $this->valuationReport->current($request->query());

        return $this->successResponse($data, 'Inventory valuation report retrieved successfully');
    }

    public function lowStock(Request $request): JsonResponse
    {
        return $this->successResponse($this->alertReport->lowStock($request->query()), 'Low stock report retrieved successfully');
    }

    public function negativeStock(Request $request): JsonResponse
    {
        return $this->successResponse($this->alertReport->negativeStock($request->query()), 'Negative stock report retrieved successfully');
    }
}
