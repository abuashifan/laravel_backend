<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\ProformaInvoice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Requests\PostSalesInvoiceRequest;
use App\Modules\Sales\Requests\SalesActionRequest;
use App\Modules\Sales\Requests\StoreSalesInvoiceRequest;
use App\Modules\Sales\Requests\UpdateSalesInvoiceRequest;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesInvoiceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SalesInvoiceService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Sales invoices retrieved successfully');
    }

    public function store(StoreSalesInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'delivery_order' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromDeliveryOrder(DeliveryOrder::query()->findOrFail((int) $data['source_id']), $data), 'Sales invoice created from delivery order successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Sales invoice created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Sales invoice retrieved successfully');
    }

    public function update(UpdateSalesInvoiceRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update(SalesInvoice::query()->findOrFail($id), $request->validated()), 'Sales invoice updated successfully');
    }

    public function createFromSalesOrder(Request $request, int $salesOrderId): JsonResponse
    {
        return $this->successResponse($this->service->createFromSalesOrder(SalesOrder::query()->findOrFail($salesOrderId), $request->all()), 'Sales invoice created from sales order successfully', 201);
    }

    public function createFromDeliveryOrder(Request $request, int $deliveryOrderId): JsonResponse
    {
        return $this->successResponse($this->service->createFromDeliveryOrder(DeliveryOrder::query()->findOrFail($deliveryOrderId), $request->all()), 'Sales invoice created from delivery order successfully', 201);
    }

    public function createFromProforma(Request $request, int $proformaId): JsonResponse
    {
        return $this->successResponse($this->service->createFromProforma(ProformaInvoice::query()->findOrFail($proformaId), $request->all()), 'Sales invoice created from proforma successfully', 201);
    }

    public function approve(int $id): JsonResponse
    {
        return $this->successResponse($this->service->approve(SalesInvoice::query()->findOrFail($id)), 'Sales invoice approved successfully');
    }

    public function post(PostSalesInvoiceRequest $request, int $id): JsonResponse
    {
        $amount = $request->validated('applied_down_payment_amount');

        return $this->successResponse(
            $this->service->post(SalesInvoice::query()->findOrFail($id), $amount !== null ? (float) $amount : null),
            'Sales invoice posted successfully'
        );
    }

    public function void(SalesActionRequest $request, int $id): JsonResponse
    {
        $request->validated();

        return $this->successResponse($this->service->void(SalesInvoice::query()->findOrFail($id), $request->input('reason')), 'Sales invoice voided successfully');
    }
}
