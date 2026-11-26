<?php

namespace App\Modules\Workspace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Services\WorkspaceReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WorkspaceReportService $reports) {}

    public function units(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->units($this->periodId($request)));
    }

    public function unit(Request $request, int $unit): JsonResponse
    {
        return $this->successResponse($this->reports->unit($this->periodId($request), $unit));
    }

    public function activity(int $document): JsonResponse
    {
        return $this->successResponse($this->reports->activity($document));
    }

    public function reconciliation(Request $request): JsonResponse
    {
        return $this->successResponse($this->reports->reconciliation($this->periodId($request)));
    }

    private function periodId(Request $request): int
    {
        return (int) $request->validate(['period_id' => ['required', 'integer', 'min:1']])['period_id'];
    }
}
