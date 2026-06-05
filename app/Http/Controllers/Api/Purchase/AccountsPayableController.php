<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Services\Purchase\APAgingService;
use App\Services\Purchase\APReconciliationService;
use App\Services\Purchase\APSubsidiaryLedgerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountsPayableController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly APSubsidiaryLedgerService $ledgerService,
        private readonly APAgingService $agingService,
        private readonly APReconciliationService $reconciliationService,
    ) {
    }

    public function vendorSummary(Request $request): JsonResponse
    {
        return $this->successResponse($this->ledgerService->vendorSummary($request->query()), 'AP vendor summary retrieved successfully');
    }

    public function vendorLedger(Request $request, int $vendorId): JsonResponse
    {
        return $this->successResponse($this->ledgerService->ledgerByVendor($vendorId, $request->query()), 'AP vendor ledger retrieved successfully');
    }

    public function billLedger(int $billId): JsonResponse
    {
        return $this->successResponse($this->ledgerService->ledgerByBill($billId), 'AP bill ledger retrieved successfully');
    }

    public function openBills(Request $request): JsonResponse
    {
        return $this->successResponse($this->ledgerService->openBills($request->query()), 'Open vendor bills retrieved successfully');
    }

    public function aging(Request $request): JsonResponse
    {
        return $this->successResponse($this->agingService->aging($request->query()), 'AP aging retrieved successfully');
    }

    public function reconciliation(Request $request): JsonResponse
    {
        return $this->successResponse($this->reconciliationService->compareSubsidiaryToGL($request->query()), 'AP reconciliation retrieved successfully');
    }
}
