<?php

namespace App\Modules\OpeningBalance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Modules\OpeningBalance\Requests\ReopenOpeningBalanceRequest;
use App\Modules\OpeningBalance\Requests\ReplaceOpeningBalanceLinesRequest;
use App\Modules\OpeningBalance\Requests\StoreOpeningBalanceBatchRequest;
use App\Modules\OpeningBalance\Requests\UpdateOpeningBalanceBatchRequest;
use App\Modules\OpeningBalance\Services\OpeningBalanceBatchService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class OpeningBalanceController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly OpeningBalanceBatchService $service) {}

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

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->batch($id)->load('lines.account', 'journalEntry'), 'Opening balance batch retrieved successfully');
    }

    public function update(UpdateOpeningBalanceBatchRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update($this->batch($id), $request->validated()), 'Opening balance batch updated successfully');
    }

    public function replaceLines(ReplaceOpeningBalanceLinesRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->replaceLines($this->batch($id), (array) $request->validated('lines')), 'Opening balance lines replaced successfully');
    }

    public function validateBatch(int $id): JsonResponse
    {
        return $this->successResponse($this->service->validate($this->batch($id)), 'Opening balance validation completed successfully');
    }

    public function preview(int $id): JsonResponse
    {
        return $this->successResponse($this->service->preview($this->batch($id)), 'Opening balance preview retrieved successfully');
    }

    public function post(int $id): JsonResponse
    {
        return $this->successResponse($this->service->post($this->batch($id)), 'Opening balance posted successfully');
    }

    public function lock(int $id): JsonResponse
    {
        return $this->successResponse($this->service->lock($this->batch($id)), 'Opening balance locked successfully');
    }

    public function reopen(ReopenOpeningBalanceRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->reopen($this->batch($id), (string) $request->validated('reason')), 'Opening balance reopened successfully');
    }

    /**
     * Batch dimuat di controller, bukan lewat implicit route model binding:
     * binding berjalan sebelum middleware tenant menyiapkan koneksi database.
     */
    private function batch(int $id): OpeningBalanceBatch
    {
        return OpeningBalanceBatch::query()->findOrFail($id);
    }
}
