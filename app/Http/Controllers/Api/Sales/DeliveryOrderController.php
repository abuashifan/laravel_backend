<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SalesActionRequest;
use App\Http\Requests\Sales\StoreDeliveryOrderRequest;
use App\Http\Requests\Sales\UpdateDeliveryOrderRequest;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\SalesOrder;
use App\Services\Sales\DeliveryOrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryOrderController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly DeliveryOrderService $service) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Delivery orders retrieved successfully'); }
    public function store(StoreDeliveryOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'sales_order' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromSalesOrder(SalesOrder::query()->findOrFail((int) $data['source_id']), $data), 'Delivery order created from sales order successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Delivery order created successfully', 201);
    }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Delivery order retrieved successfully'); }
    public function update(UpdateDeliveryOrderRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->update(DeliveryOrder::query()->findOrFail($id), $request->validated()), 'Delivery order updated successfully'); }
    public function createFromSalesOrder(Request $request, int $salesOrderId): JsonResponse { return $this->successResponse($this->service->createFromSalesOrder(SalesOrder::query()->findOrFail($salesOrderId), $request->all()), 'Delivery order created from sales order successfully', 201); }
    public function ready(int $id): JsonResponse { return $this->successResponse($this->service->markReady(DeliveryOrder::query()->findOrFail($id)), 'Delivery order marked ready successfully'); }
    public function ship(int $id): JsonResponse { return $this->successResponse($this->service->ship(DeliveryOrder::query()->findOrFail($id)), 'Delivery order shipped successfully'); }
    public function deliver(int $id): JsonResponse { return $this->successResponse($this->service->deliver(DeliveryOrder::query()->findOrFail($id)), 'Delivery order delivered successfully'); }
    public function cancel(SalesActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->cancel(DeliveryOrder::query()->findOrFail($id), $request->validated('reason')), 'Delivery order cancelled successfully'); }
    public function void(SalesActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(DeliveryOrder::query()->findOrFail($id), $request->validated('reason')), 'Delivery order voided successfully'); }
}
