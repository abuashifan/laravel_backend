<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\PurchaseRequestActionRequest;
use App\Http\Requests\Purchase\StorePurchaseReturnRequest;
use App\Http\Requests\Purchase\UpdatePurchaseReturnRequest;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\VendorBill;
use App\Services\Purchase\PurchaseReturnService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseReturnController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PurchaseReturnService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Purchase returns retrieved successfully');
    }

    public function store(StorePurchaseReturnRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Purchase return created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Purchase return retrieved successfully');
    }

    public function update(UpdatePurchaseReturnRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update(PurchaseReturn::query()->findOrFail($id), $request->validated()), 'Purchase return updated successfully');
    }

    public function createFromVendorBill(Request $request, int $billId): JsonResponse
    {
        return $this->successResponse($this->service->createFromVendorBill(VendorBill::query()->findOrFail($billId), $request->all()), 'Purchase return created from vendor bill successfully', 201);
    }

    public function createFromGoodsReceipt(Request $request, int $goodsReceiptId): JsonResponse
    {
        return $this->successResponse($this->service->createFromGoodsReceipt(GoodsReceipt::query()->findOrFail($goodsReceiptId), $request->all()), 'Purchase return created from goods receipt successfully', 201);
    }

    public function approve(int $id): JsonResponse
    {
        return $this->successResponse($this->service->approve(PurchaseReturn::query()->findOrFail($id)), 'Purchase return approved successfully');
    }

    public function post(int $id): JsonResponse
    {
        return $this->successResponse($this->service->post(PurchaseReturn::query()->findOrFail($id)), 'Purchase return posted successfully');
    }

    public function void(PurchaseRequestActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->void(PurchaseReturn::query()->findOrFail($id), $request->validated('reason')), 'Purchase return voided successfully');
    }
}
