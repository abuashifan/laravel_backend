<?php

namespace App\Http\Controllers\Api\Purchase;

use App\Http\Controllers\Controller;
use App\Http\Requests\Purchase\AllocateVendorDepositRequest;
use App\Http\Requests\Purchase\PurchaseRequestActionRequest;
use App\Http\Requests\Purchase\RefundVendorDepositRequest;
use App\Http\Requests\Purchase\StoreVendorDepositRequest;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorDeposit;
use App\Services\Permissions\PermissionService;
use App\Services\Purchase\VendorDepositService;
use App\Support\Api\ApiErrorCode;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VendorDepositController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly VendorDepositService $service, private readonly PermissionService $permissionService) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Vendor deposits retrieved successfully'); }
    public function store(StoreVendorDepositRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Vendor deposit created successfully', 201); }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Vendor deposit retrieved successfully'); }
    public function post(int $id): JsonResponse { return $this->successResponse($this->service->post(VendorDeposit::query()->findOrFail($id)), 'Vendor deposit posted successfully'); }
    public function void(PurchaseRequestActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(VendorDeposit::query()->findOrFail($id), $request->validated('reason')), 'Vendor deposit voided successfully'); }
    public function refund(RefundVendorDepositRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->refund(VendorDeposit::query()->findOrFail($id), (float) $request->validated('amount'), $request->validated('reason')), 'Vendor deposit refunded successfully'); }
    public function available(Request $request): JsonResponse
    {
        if (! $this->canAny(['purchase.deposits.view', 'purchase.payments.view'])) {
            return $this->errorCodeResponse(ApiErrorCode::PERMISSION_DENIED, 'User does not have permission to view available vendor deposits.', [], 403);
        }

        $data = $request->validate([
            'vendor_id' => ['required', 'integer'],
            'purchase_order_id' => ['nullable', 'integer'],
            'vendor_bill_id' => ['nullable', 'integer'],
        ]);

        $payload = ! empty($data['vendor_bill_id'])
            ? $this->service->availableForBill((int) $data['vendor_bill_id'])
            : $this->service->availableForVendor((int) $data['vendor_id'], $data);

        return $this->successResponse($payload, 'Available vendor deposits retrieved successfully');
    }

    public function allocateToBill(AllocateVendorDepositRequest $request, int $id, int $billId): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($this->service->allocateToBill(
            VendorDeposit::query()->findOrFail($id),
            VendorBill::query()->findOrFail($billId),
            $request->allocatedAmount(),
            [
                'allocation_date' => $data['allocation_date'] ?? null,
                'source_context' => $data['source_context'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        ), 'Vendor deposit allocated successfully');
    }

    private function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->permissionService->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
