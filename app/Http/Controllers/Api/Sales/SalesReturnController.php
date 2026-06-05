<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesActionRequest;
use App\Http\Requests\Sales\StoreSalesReturnRequest;
use App\Http\Requests\Sales\UpdateSalesReturnRequest;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesReturn;
use App\Services\Sales\SalesReturnService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly SalesReturnService $service) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Sales returns retrieved successfully'); }
    public function store(StoreSalesReturnRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Sales return created successfully', 201); }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Sales return retrieved successfully'); }
    public function update(UpdateSalesReturnRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->update(SalesReturn::query()->findOrFail($id), $request->validated()), 'Sales return updated successfully'); }
    public function createFromSalesInvoice(Request $request, int $invoiceId): JsonResponse { return $this->successResponse($this->service->createFromSalesInvoice(SalesInvoice::query()->findOrFail($invoiceId), $request->all()), 'Sales return created from invoice successfully', 201); }
    public function createFromDeliveryOrder(Request $request, int $deliveryOrderId): JsonResponse { return $this->successResponse($this->service->createFromDeliveryOrder(DeliveryOrder::query()->findOrFail($deliveryOrderId), $request->all()), 'Sales return created from delivery order successfully', 201); }
    public function approve(int $id): JsonResponse { return $this->successResponse($this->service->approve(SalesReturn::query()->findOrFail($id)), 'Sales return approved successfully'); }
    public function post(int $id): JsonResponse { return $this->successResponse($this->service->post(SalesReturn::query()->findOrFail($id)), 'Sales return posted successfully'); }
    public function void(SalesActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(SalesReturn::query()->findOrFail($id), $request->validated('reason')), 'Sales return voided successfully'); }
}
