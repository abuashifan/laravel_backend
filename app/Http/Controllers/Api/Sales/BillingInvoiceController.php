<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesActionRequest;
use App\Http\Requests\Sales\StoreBillingInvoiceRequest;
use App\Models\Tenant\BillingInvoice;
use App\Models\Tenant\SalesInvoice;
use App\Services\Sales\BillingInvoiceService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BillingInvoiceController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly BillingInvoiceService $service) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Billing invoices retrieved successfully'); }
    public function store(StoreBillingInvoiceRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Billing invoice created successfully', 201); }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Billing invoice retrieved successfully'); }
    public function createFromSalesInvoice(Request $request, int $salesInvoiceId): JsonResponse { return $this->successResponse($this->service->createFromSalesInvoice(SalesInvoice::query()->findOrFail($salesInvoiceId), $request->all()), 'Billing invoice created from sales invoice successfully', 201); }
    public function issue(int $id): JsonResponse { return $this->successResponse($this->service->issue(BillingInvoice::query()->findOrFail($id)), 'Billing invoice issued successfully'); }
    public function cancel(SalesActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->cancel(BillingInvoice::query()->findOrFail($id), $request->validated('reason')), 'Billing invoice cancelled successfully'); }
}
