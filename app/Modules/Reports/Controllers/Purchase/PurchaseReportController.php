<?php

namespace App\Modules\Reports\Controllers\Purchase;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\Purchase\PurchaseByProductReportRequest;
use App\Modules\Reports\Requests\Purchase\PurchaseByVendorReportRequest;
use App\Modules\Reports\Requests\Purchase\PurchaseSummaryReportRequest;
use App\Modules\Reports\Services\Purchase\PurchaseByProductReportService;
use App\Modules\Reports\Services\Purchase\PurchaseByVendorReportService;
use App\Modules\Reports\Services\Purchase\PurchaseSummaryReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class PurchaseReportController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly PurchaseSummaryReportService $summaryService,
        private readonly PurchaseByVendorReportService $byVendorService,
        private readonly PurchaseByProductReportService $byProductService,
    ) {}

    public function summary(PurchaseSummaryReportRequest $request): JsonResponse
    {
        $result = $this->summaryService->getReport($request->validated());

        return $this->successResponse($result, 'Purchase summary retrieved successfully');
    }

    public function byVendor(PurchaseByVendorReportRequest $request): JsonResponse
    {
        $result = $this->byVendorService->getReport($request->validated());

        return $this->successResponse($result, 'Purchase by vendor retrieved successfully');
    }

    public function byProduct(PurchaseByProductReportRequest $request): JsonResponse
    {
        $result = $this->byProductService->getReport($request->validated());

        return $this->successResponse($result, 'Purchase by product retrieved successfully');
    }
}
