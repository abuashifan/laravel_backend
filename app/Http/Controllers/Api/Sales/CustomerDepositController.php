<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\AllocateCustomerDepositRequest;
use App\Http\Requests\Sales\RefundCustomerDepositRequest;
use App\Http\Requests\Sales\SalesActionRequest;
use App\Http\Requests\Sales\StoreCustomerDepositRequest;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\SalesInvoice;
use App\Services\Sales\CustomerDepositService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerDepositController extends Controller
{
    use ApiResponse;
    public function __construct(private readonly CustomerDepositService $service) {}
    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Customer deposits retrieved successfully'); }
    public function store(StoreCustomerDepositRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Customer deposit created successfully', 201); }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Customer deposit retrieved successfully'); }
    public function post(int $id): JsonResponse { return $this->successResponse($this->service->post(CustomerDeposit::query()->findOrFail($id)), 'Customer deposit posted successfully'); }
    public function void(SalesActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(CustomerDeposit::query()->findOrFail($id), $request->validated('reason')), 'Customer deposit voided successfully'); }
    public function refund(RefundCustomerDepositRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->refund(CustomerDeposit::query()->findOrFail($id), (float) $request->validated('amount'), $request->validated('reason')), 'Customer deposit refunded successfully'); }
    public function allocateToInvoice(AllocateCustomerDepositRequest $request, int $id, int $invoiceId): JsonResponse { return $this->successResponse($this->service->allocateToInvoice(CustomerDeposit::query()->findOrFail($id), SalesInvoice::query()->findOrFail($invoiceId), (float) $request->validated('amount')), 'Customer deposit allocated successfully'); }
}
