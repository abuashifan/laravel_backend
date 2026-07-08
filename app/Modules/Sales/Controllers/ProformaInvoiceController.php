<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\ProformaInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use App\Modules\Sales\Requests\SalesActionRequest;
use App\Modules\Sales\Requests\StoreProformaInvoiceRequest;
use App\Modules\Sales\Requests\UpdateProformaInvoiceRequest;
use App\Modules\Sales\Services\ProformaInvoiceService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProformaInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProformaInvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Proforma invoices retrieved successfully');
    }

    public function store(StoreProformaInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'sales_quotation' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromQuotation(SalesQuotation::query()->findOrFail((int) $data['source_id']), $data), 'Proforma invoice created from quotation successfully', 201);
        }
        if (($data['source_type'] ?? null) === 'sales_order' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromSalesOrder(SalesOrder::query()->findOrFail((int) $data['source_id']), $data), 'Proforma invoice created from sales order successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Proforma invoice created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Proforma invoice retrieved successfully');
    }

    public function update(UpdateProformaInvoiceRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update(ProformaInvoice::query()->findOrFail($id), $request->validated()), 'Proforma invoice updated successfully');
    }

    public function createFromQuotation(Request $request, int $quotationId): JsonResponse
    {
        return $this->successResponse($this->service->createFromQuotation(SalesQuotation::query()->findOrFail($quotationId), $request->all()), 'Proforma invoice created from quotation successfully', 201);
    }

    public function createFromSalesOrder(Request $request, int $salesOrderId): JsonResponse
    {
        return $this->successResponse($this->service->createFromSalesOrder(SalesOrder::query()->findOrFail($salesOrderId), $request->all()), 'Proforma invoice created from sales order successfully', 201);
    }

    public function issue(int $id): JsonResponse
    {
        return $this->successResponse($this->service->issue(ProformaInvoice::query()->findOrFail($id)), 'Proforma invoice issued successfully');
    }

    public function accept(int $id): JsonResponse
    {
        return $this->successResponse($this->service->accept(ProformaInvoice::query()->findOrFail($id)), 'Proforma invoice accepted successfully');
    }

    public function cancel(SalesActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->cancel(ProformaInvoice::query()->findOrFail($id), $request->validated('reason')), 'Proforma invoice cancelled successfully');
    }
}
