<?php

namespace App\Http\Controllers\Api\CashBank;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashBank\CashBankActionRequest;
use App\Http\Requests\CashBank\StoreCashPaymentRequest;
use App\Models\Tenant\CashPayment;
use App\Services\CashBank\CashPaymentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashPaymentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashPaymentService $service) {}

    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Cash payments retrieved successfully'); }
    public function store(StoreCashPaymentRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Cash payment created successfully', 201); }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Cash payment retrieved successfully'); }
    public function post(int $id): JsonResponse { return $this->successResponse($this->service->post(CashPayment::query()->findOrFail($id)), 'Cash payment posted successfully'); }
    public function void(CashBankActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(CashPayment::query()->findOrFail($id), $request->validated('reason')), 'Cash payment voided successfully'); }
}

