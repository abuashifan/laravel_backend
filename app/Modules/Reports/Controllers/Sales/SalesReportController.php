<?php

namespace App\Modules\Reports\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\Sales\SalesByCustomerReportRequest;
use App\Modules\Reports\Requests\Sales\SalesByProductReportRequest;
use App\Modules\Reports\Requests\Sales\SalesSummaryReportRequest;
use App\Modules\Reports\Services\Sales\SalesByCustomerReportService;
use App\Modules\Reports\Services\Sales\SalesByProductReportService;
use App\Modules\Reports\Services\Sales\SalesSummaryReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class SalesReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly SalesSummaryReportService $summaryService,
        private readonly SalesByCustomerReportService $byCustomerService,
        private readonly SalesByProductReportService $byProductService,
    ) {}

    public function summary(SalesSummaryReportRequest $request): JsonResponse
    {
        $result = $this->summaryService->getReport($request->validated());

        return $this->successResponse($result, 'Sales summary retrieved successfully');
    }

    public function byCustomer(SalesByCustomerReportRequest $request): JsonResponse
    {
        $result = $this->byCustomerService->getReport($request->validated());

        return $this->successResponse($result, 'Sales by customer retrieved successfully');
    }

    public function byProduct(SalesByProductReportRequest $request): JsonResponse
    {
        $result = $this->byProductService->getReport($request->validated());

        return $this->successResponse($result, 'Sales by product retrieved successfully');
    }
}
