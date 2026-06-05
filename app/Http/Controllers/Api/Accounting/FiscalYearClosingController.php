<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\CloseFiscalYearRequest;
use App\Http\Requests\Accounting\ReopenFiscalYearRequest;
use App\Services\Accounting\FiscalYearClosingService;
use App\Support\Api\ApiResponseBuilder;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class FiscalYearClosingController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FiscalYearClosingService $service)
    {
    }

    public function preview(int|string $id): JsonResponse
    {
        $result = $this->service->previewClosing((int) $id);
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Invalid closing preview request.', [
                'warnings' => $result['warnings'] ?? [],
            ]);
        }

        return $this->successResponse($result, 'Closing preview retrieved successfully');
    }

    public function checklist(int|string $id): JsonResponse
    {
        $result = $this->service->generateClosingChecklist((int) $id);

        return $this->successResponse($result, 'Closing checklist retrieved successfully');
    }

    public function close(CloseFiscalYearRequest $request, int|string $id): JsonResponse
    {
        $result = $this->service->executeClosing((int) $id, $request->validated());
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Closing validation failed.', [
                'warnings' => $result['warnings'] ?? [],
            ]);
        }

        return $this->successResponse($result, 'Fiscal year closed successfully');
    }

    public function reopen(ReopenFiscalYearRequest $request, int|string $id): JsonResponse
    {
        $result = $this->service->reopenFiscalYear((int) $id, $request->validated());
        if (! ($result['valid'] ?? false)) {
            return ApiResponseBuilder::validation((array) ($result['errors'] ?? []), 'Reopen validation failed.', [
                'filter' => $request->validated(),
            ]);
        }

        return $this->successResponse($result, 'Fiscal year reopened successfully');
    }
}
