<?php

namespace App\Http\Controllers\Api\CashBank;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashBank\CashBankActionRequest;
use App\Http\Requests\CashBank\StoreCashReceiptRequest;
use App\Models\Tenant\CashReceipt;
use App\Services\CashBank\CashReceiptService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashReceiptController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly CashReceiptService $service) {}

    public function index(Request $request): JsonResponse { return $this->listResponse($this->service->list($request->query()), $request, 'Cash receipts retrieved successfully'); }
    public function store(StoreCashReceiptRequest $request): JsonResponse { return $this->successResponse($this->service->create($request->validated()), 'Cash receipt created successfully', 201); }
    public function show(int $id): JsonResponse { return $this->successResponse($this->service->find($id), 'Cash receipt retrieved successfully'); }
    public function post(int $id): JsonResponse { return $this->successResponse($this->service->post(CashReceipt::query()->findOrFail($id)), 'Cash receipt posted successfully'); }
    public function void(CashBankActionRequest $request, int $id): JsonResponse { return $this->successResponse($this->service->void(CashReceipt::query()->findOrFail($id), $request->validated('reason')), 'Cash receipt voided successfully'); }
}

