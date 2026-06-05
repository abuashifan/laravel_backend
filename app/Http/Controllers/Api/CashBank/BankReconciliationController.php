<?php

namespace App\Http\Controllers\Api\CashBank;

use App\Http\Controllers\Controller;
use App\Http\Requests\CashBank\MarkBankReconciliationLinesRequest;
use App\Http\Requests\CashBank\StoreBankReconciliationRequest;
use App\Http\Requests\CashBank\UpdateBankReconciliationRequest;
use App\Models\Tenant\BankReconciliation;
use App\Services\CashBank\BankReconciliationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankReconciliationController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BankReconciliationService $service) {}

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Bank reconciliations retrieved successfully');
    }

    public function store(StoreBankReconciliationRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Bank reconciliation created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Bank reconciliation retrieved successfully');
    }

    public function update(UpdateBankReconciliationRequest $request, int $id): JsonResponse
    {
        $rec = BankReconciliation::query()->findOrFail($id);
        return $this->successResponse($this->service->update($rec, $request->validated()), 'Bank reconciliation updated successfully');
    }

    public function refreshLines(int $id): JsonResponse
    {
        $rec = BankReconciliation::query()->findOrFail($id);
        return $this->successResponse($this->service->refreshLines($rec), 'Bank reconciliation lines refreshed successfully');
    }

    public function markLines(MarkBankReconciliationLinesRequest $request, int $id): JsonResponse
    {
        $rec = BankReconciliation::query()->findOrFail($id);
        $data = $request->validated();
        return $this->successResponse(
            $this->service->markLines($rec, (array) $data['line_ids'], (bool) $data['cleared'], $data['cleared_date'] ?? null),
            'Bank reconciliation lines updated successfully'
        );
    }
}

