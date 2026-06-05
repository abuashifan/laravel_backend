<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\PurchaseRequestActionRequest;
use App\Http\Requests\Purchase\StorePurchaseOrderRequest;
use App\Http\Requests\Purchase\UpdatePurchaseOrderRequest;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseRequest;
use App\Services\Purchase\PurchaseOrderService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PurchaseOrderService $service) {}

    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Purchase orders retrieved successfully'); }
    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'purchase_request' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromPurchaseRequest(PurchaseRequest::query()->findOrFail((int) $data['source_id']), $data), 'Purchase order created from purchase request successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Purchase order created successfully', 201);
    }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Purchase order retrieved successfully'); }
    public function update(UpdatePurchaseOrderRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->update(PurchaseOrder::query()->findOrFail($id), $request->validated()), 'Purchase order updated successfully'); }
    public function createFromPurchaseRequest(Request $request, int $purchaseRequestId): JsonResponse { return $this->successResponse($this->service->createFromPurchaseRequest(PurchaseRequest::query()->findOrFail($purchaseRequestId), $request->all()), 'Purchase order created from purchase request successfully', 201); }
    public function approve(int $id): JsonResponse { return $this->successResponse($this->service->approve(PurchaseOrder::query()->findOrFail($id)), 'Purchase order approved successfully'); }
    public function confirm(int $id): JsonResponse { return $this->successResponse($this->service->confirm(PurchaseOrder::query()->findOrFail($id)), 'Purchase order confirmed successfully'); }
    public function cancel(PurchaseRequestActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->cancel(PurchaseOrder::query()->findOrFail($id), $request->validated('reason')), 'Purchase order cancelled successfully'); }
    public function close(int $id): JsonResponse { return $this->successResponse($this->service->close(PurchaseOrder::query()->findOrFail($id)), 'Purchase order closed successfully'); }
}
