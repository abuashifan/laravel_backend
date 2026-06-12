<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Http\Requests\Reports\ReconciliationReportRequest;
use App\Services\Reports\ReconciliationReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class ReconciliationReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ReconciliationReportService $service)
    {
    }

    public function ar(ReconciliationReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->ar($request->validated()), 'AR reconciliation report retrieved successfully');
    }

    public function ap(ReconciliationReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->ap($request->validated()), 'AP reconciliation report retrieved successfully');
    }

    public function inventory(ReconciliationReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->inventory($request->validated()), 'Inventory reconciliation report retrieved successfully');
    }

    public function grni(ReconciliationReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->grni($request->validated()), 'GRNI reconciliation report retrieved successfully');
    }

    public function customerDeposits(ReconciliationReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->customerDeposits($request->validated()), 'Unapplied customer deposit report retrieved successfully');
    }

    public function vendorDeposits(ReconciliationReportRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->vendorDeposits($request->validated()), 'Unapplied vendor deposit report retrieved successfully');
    }
}
