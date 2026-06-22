<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Services\Sales\ARAgingService;
use App\Services\Sales\ARReconciliationService;
use App\Services\Sales\ARSubsidiaryLedgerService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountsReceivableController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ARSubsidiaryLedgerService $ledgerService,
        private readonly ARAgingService $agingService,
        private readonly ARReconciliationService $reconciliationService,
    ) {}

    public function customerSummary(Request $request): JsonResponse
    {
        return $this->successResponse($this->ledgerService->customerSummary($request->query()), 'AR customer summary retrieved successfully');
    }

    public function customerLedger(Request $request, int $customerId): JsonResponse
    {
        return $this->successResponse($this->ledgerService->ledgerByCustomer($customerId, $request->query()), 'AR customer ledger retrieved successfully');
    }

    public function invoiceLedger(Request $request, int $invoiceId): JsonResponse
    {
        return $this->successResponse($this->ledgerService->ledgerByInvoice($invoiceId, $request->query()), 'AR invoice ledger retrieved successfully');
    }

    public function openInvoices(Request $request): JsonResponse
    {
        return $this->successResponse($this->ledgerService->openInvoices($request->query()), 'Open invoices retrieved successfully');
    }

    public function aging(Request $request): JsonResponse
    {
        return $this->successResponse($this->agingService->aging($request->query()), 'AR aging retrieved successfully');
    }

    public function reconciliation(Request $request): JsonResponse
    {
        return $this->successResponse($this->reconciliationService->compareSubsidiaryToGL($request->query()), 'AR reconciliation retrieved successfully');
    }
}
