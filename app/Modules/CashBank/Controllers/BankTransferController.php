<?php

namespace App\Modules\CashBank\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\Models\BankTransfer;
use App\Modules\CashBank\Requests\CashBankActionRequest;
use App\Modules\CashBank\Requests\StoreBankTransferRequest;
use App\Modules\CashBank\Requests\UpdateBankTransferRequest;
use App\Modules\CashBank\Services\BankTransferService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use App\Shared\TransactionLifecycle\DeleteTransactionRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankTransferController extends Controller
{
    use ApiResponse;
    use ResolvesAdjacentRecords;

    public function __construct(private readonly BankTransferService $service) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(BankTransfer::query(), $request, 'transfer_number');
    }

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

    public function post(int $id): JsonResponse
    {
        return $this->successResponse($this->service->post(BankTransfer::query()->findOrFail($id)), 'Bank transfer posted successfully');
    }

    public function update(UpdateBankTransferRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update(BankTransfer::query()->findOrFail($id), $request->validated()), 'Bank transfer updated successfully');
    }

    public function destroy(DeleteTransactionRequest $request, int $id): JsonResponse
    {
        $this->service->delete(BankTransfer::query()->findOrFail($id), $request->validated('reason'));

        return $this->successResponse(null, 'Bank transfer deleted successfully');
    }

    public function void(CashBankActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->void(BankTransfer::query()->findOrFail($id), $request->validated('reason')), 'Bank transfer voided successfully');
    }
}
