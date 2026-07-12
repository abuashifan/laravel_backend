<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\SavedReportRequest;
use App\Modules\Reports\Services\SavedReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * CRUD Laporan Tersimpan (Fase 13). Pemilik penuh; penerima berbagi hanya baca.
 */
class SavedReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly SavedReportService $service) {}

    public function index(): JsonResponse
    {
        return $this->successResponse($this->service->listForUser($this->userId()), 'Saved reports retrieved successfully');
    }

    public function store(SavedReportRequest $request): JsonResponse
    {
        $report = $this->service->create($this->userId(), $request->validated());

        return $this->successResponse($report, 'Saved report created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $report = $this->service->show($this->userId(), $id);
        if ($report === null) {
            return $this->errorResponse('Saved report not found', 404);
        }

        return $this->successResponse($report, 'Saved report retrieved successfully');
    }

    public function update(SavedReportRequest $request, int $id): JsonResponse
    {
        $result = $this->service->update($this->userId(), $id, $request->validated());
        if ($result === null) {
            return $this->errorResponse('Saved report not found', 404);
        }
        if ($result === false) {
            return $this->errorResponse('You can only modify your own saved reports', 403);
        }

        return $this->successResponse($result, 'Saved report updated successfully');
    }

    public function destroy(int $id): JsonResponse
    {
        $result = $this->service->delete($this->userId(), $id);
        if ($result === null) {
            return $this->errorResponse('Saved report not found', 404);
        }
        if ($result === false) {
            return $this->errorResponse('You can only delete your own saved reports', 403);
        }

        return $this->successResponse(null, 'Saved report deleted successfully');
    }

    private function userId(): int
    {
        return (int) auth()->id();
    }
}
