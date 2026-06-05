<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\PurchaseRequestActionRequest;
use App\Http\Requests\Purchase\StoreGoodsReceiptRequest;
use App\Http\Requests\Purchase\UpdateGoodsReceiptRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Services\Purchase\GoodsReceiptService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly GoodsReceiptService $service) {}

    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Goods receipts retrieved successfully'); }
    public function store(StoreGoodsReceiptRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'purchase_order' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromPurchaseOrder(PurchaseOrder::query()->findOrFail((int) $data['source_id']), $data), 'Goods receipt created from purchase order successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Goods receipt created successfully', 201);
    }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Goods receipt retrieved successfully'); }
    public function update(UpdateGoodsReceiptRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->update(GoodsReceipt::query()->findOrFail($id), $request->validated()), 'Goods receipt updated successfully'); }
    public function createFromPurchaseOrder(Request $request, int $purchaseOrderId): JsonResponse { return $this->successResponse($this->service->createFromPurchaseOrder(PurchaseOrder::query()->findOrFail($purchaseOrderId), $request->all()), 'Goods receipt created from purchase order successfully', 201); }
    public function receive(int $id): JsonResponse { return $this->successResponse($this->service->receive(GoodsReceipt::query()->findOrFail($id)), 'Goods receipt received successfully'); }
    public function cancel(PurchaseRequestActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->cancel(GoodsReceipt::query()->findOrFail($id), $request->validated('reason')), 'Goods receipt cancelled successfully'); }
    public function void(PurchaseRequestActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(GoodsReceipt::query()->findOrFail($id), $request->validated('reason')), 'Goods receipt voided successfully'); }
}
