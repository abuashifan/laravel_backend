<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\PurchaseRequestActionRequest;
use App\Http\Requests\Purchase\StoreVendorPaymentRequest;
use App\Models\Tenant\VendorPayment;
use App\Services\Purchase\VendorPaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorPaymentController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly VendorPaymentService $service) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Vendor payments retrieved successfully'); }
    public function store(StoreVendorPaymentRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Vendor payment created successfully', 201); }
    public function vendorContext(Request $request): JsonResponse
    {
        $data = $request->validate(['vendor_id' => ['required', 'integer']]);

        return $this->successResponse($this->service->vendorContext((int) $data['vendor_id']), 'Vendor payment context retrieved successfully');
    }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Vendor payment retrieved successfully'); }
    public function post(int $id): JsonResponse { return $this->successResponse($this->service->post(VendorPayment::query()->findOrFail($id)), 'Vendor payment posted successfully'); }
    public function void(PurchaseRequestActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(VendorPayment::query()->findOrFail($id), $request->validated('reason')), 'Vendor payment voided successfully'); }
}
