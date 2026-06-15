<?php

namespace App\Http\Controllers\Api\OpeningBalance;

use App\Http\Controllers\Controller;
use App\Http\Requests\OpeningBalance\ReopenOpeningBalanceRequest;
use App\Http\Requests\OpeningBalance\ReplaceOpeningBalanceLinesRequest;
use App\Http\Requests\OpeningBalance\StoreOpeningBalanceBatchRequest;
use App\Http\Requests\OpeningBalance\UpdateOpeningBalanceBatchRequest;
use App\Models\Tenant\OpeningBalanceBatch;
use App\Services\OpeningBalance\OpeningBalanceBatchService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class OpeningBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OpeningBalanceBatchService $service)
    {
    }

    public function status(): JsonResponse
    {
        return $this->successResponse($this->service->status(), 'Opening balance status retrieved successfully');
    }

    public function index(): JsonResponse
    {
        return $this->successResponse($this->service->list(), 'Opening balance batches retrieved successfully');
    }

    public function store(StoreOpeningBalanceBatchRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Opening balance batch created successfully', 201);
    }

    public function show(OpeningBalanceBatch $batch): JsonResponse
    {
        return $this->successResponse($batch->load('lines.account', 'journalEntry'), 'Opening balance batch retrieved successfully');
    }

    public function update(UpdateOpeningBalanceBatchRequest $request, OpeningBalanceBatch $batch): JsonResponse
    {
        return $this->successResponse($this->service->update($batch, $request->validated()), 'Opening balance batch updated successfully');
    }

    public function replaceLines(ReplaceOpeningBalanceLinesRequest $request, OpeningBalanceBatch $batch): JsonResponse
    {
        return $this->successResponse($this->service->replaceLines($batch, (array) $request->validated('lines')), 'Opening balance lines replaced successfully');
    }

    public function validateBatch(OpeningBalanceBatch $batch): JsonResponse
    {
        return $this->successResponse($this->service->validate($batch), 'Opening balance validation completed successfully');
    }

    public function preview(OpeningBalanceBatch $batch): JsonResponse
    {
        return $this->successResponse($this->service->preview($batch), 'Opening balance preview retrieved successfully');
    }

    public function post(OpeningBalanceBatch $batch): JsonResponse
    {
        return $this->successResponse($this->service->post($batch), 'Opening balance posted successfully');
    }

    public function lock(OpeningBalanceBatch $batch): JsonResponse
    {
        return $this->successResponse($this->service->lock($batch), 'Opening balance locked successfully');
    }

    public function reopen(ReopenOpeningBalanceRequest $request, OpeningBalanceBatch $batch): JsonResponse
    {
        return $this->successResponse($this->service->reopen($batch, (string) $request->validated('reason')), 'Opening balance reopened successfully');
    }
}
