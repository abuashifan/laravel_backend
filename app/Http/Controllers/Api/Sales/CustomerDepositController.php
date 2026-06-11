<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AllocateCustomerDepositRequest;
use App\Http\Requests\Sales\RefundCustomerDepositRequest;
use App\Http\Requests\Sales\SalesActionRequest;
use App\Http\Requests\Sales\StoreCustomerDepositRequest;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\SalesInvoice;
use App\Services\Permissions\PermissionService;
use App\Services\Sales\CustomerDepositService;
use App\Traits\ApiResponse;
use App\Support\Api\ApiErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerDepositController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly CustomerDepositService $service, private readonly PermissionService $permissionService) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Customer deposits retrieved successfully'); }
    public function store(StoreCustomerDepositRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Customer deposit created successfully', 201); }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Customer deposit retrieved successfully'); }
    public function post(int $id): JsonResponse { return $this->successResponse($this->service->post(CustomerDeposit::query()->findOrFail($id)), 'Customer deposit posted successfully'); }
    public function void(SalesActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(CustomerDeposit::query()->findOrFail($id), $request->validated('reason')), 'Customer deposit voided successfully'); }
    public function refund(RefundCustomerDepositRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->refund(CustomerDeposit::query()->findOrFail($id), (float) $request->validated('amount'), $request->validated('reason')), 'Customer deposit refunded successfully'); }
    public function available(Request $request): JsonResponse
    {
        if (! $this->canAny(['sales.deposits.view', 'sales.receipts.view'])) {
            return $this->errorCodeResponse(ApiErrorCode::PERMISSION_DENIED, 'User does not have permission to view available customer deposits.', [], 403);
        }

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'sales_order_id' => ['nullable', 'integer'],
            'sales_invoice_id' => ['nullable', 'integer'],
        ]);

        $payload = ! empty($data['sales_invoice_id'])
            ? $this->service->availableForInvoice((int) $data['sales_invoice_id'])
            : $this->service->availableForCustomer((int) $data['customer_id'], $data);

        return $this->successResponse($payload, 'Available customer deposits retrieved successfully');
    }
    public function allocateToInvoice(AllocateCustomerDepositRequest $request, int $id, int $invoiceId): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse($this->service->allocateToInvoice(
            CustomerDeposit::query()->findOrFail($id),
            SalesInvoice::query()->findOrFail($invoiceId),
            $request->allocatedAmount(),
            [
                'allocation_date' => $data['allocation_date'] ?? null,
                'source_context' => $data['source_context'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]
        ), 'Customer deposit allocated successfully');
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
