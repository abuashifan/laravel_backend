<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\PostVendorBillRequest;
use App\Http\Requests\Purchase\PurchaseRequestActionRequest;
use App\Http\Requests\Purchase\StoreVendorBillRequest;
use App\Http\Requests\Purchase\UpdateVendorBillRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\VendorBill;
use App\Services\Purchase\VendorBillService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorBillController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly VendorBillService $service) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Vendor bills retrieved successfully'); }
    public function store(StoreVendorBillRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'goods_receipt' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromGoodsReceipt(GoodsReceipt::query()->findOrFail((int) $data['source_id']), $data), 'Vendor bill created from goods receipt successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Vendor bill created successfully', 201);
    }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Vendor bill retrieved successfully'); }
    public function update(UpdateVendorBillRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->update(VendorBill::query()->findOrFail($id), $request->validated()), 'Vendor bill updated successfully'); }
    public function createFromPurchaseOrder(Request $request, int $purchaseOrderId): JsonResponse { return $this->successResponse($this->service->createFromPurchaseOrder(PurchaseOrder::query()->findOrFail($purchaseOrderId), $request->all()), 'Vendor bill created from purchase order successfully', 201); }
    public function createFromGoodsReceipt(Request $request, int $goodsReceiptId): JsonResponse { return $this->successResponse($this->service->createFromGoodsReceipt(GoodsReceipt::query()->findOrFail($goodsReceiptId), $request->all()), 'Vendor bill created from goods receipt successfully', 201); }
    public function approve(int $id): JsonResponse { return $this->successResponse($this->service->approve(VendorBill::query()->findOrFail($id)), 'Vendor bill approved successfully'); }
    public function post(PostVendorBillRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->post(VendorBill::query()->findOrFail($id), $request->validated('applied_vendor_deposit_amount')), 'Vendor bill posted successfully'); }
    public function void(PurchaseRequestActionRequest $request, int $id): JsonResponse { $request->validated(); return $this->successResponse($this->service->void(VendorBill::query()->findOrFail($id), $request->input('reason')), 'Vendor bill voided successfully'); }
}
