<?php

namespace App\Http\Controllers\Api\CashBank;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashBank\CashBankActionRequest;
use App\Http\Requests\CashBank\StoreBankTransferRequest;
use App\Http\Requests\CashBank\UpdateBankTransferRequest;
use App\Models\Tenant\BankTransfer;
use App\Services\CashBank\BankTransferService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankTransferController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BankTransferService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Bank transfers retrieved successfully');
    }

    public function store(StoreBankTransferRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Bank transfer created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Bank transfer retrieved successfully');
    }

    public function update(UpdateBankTransferRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update(BankTransfer::query()->findOrFail($id), $request->validated()), 'Bank transfer updated successfully');
    }

    public function post(int $id): JsonResponse
    {
        return $this->successResponse($this->service->post(BankTransfer::query()->findOrFail($id)), 'Bank transfer posted successfully');
    }

    public function void(CashBankActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->void(BankTransfer::query()->findOrFail($id), $request->validated('reason')), 'Bank transfer voided successfully');
    }
}
