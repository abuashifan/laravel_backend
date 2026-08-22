<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\MultiPeriodReportRequest;
use App\Modules\Reports\Services\MultiPeriodReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class MultiPeriodReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly MultiPeriodReportService $service) {}

    public function profitLoss(MultiPeriodReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(
            $this->service->profitLoss($data['periods'], $this->dimensions($data)),
            'Multi-period profit & loss report retrieved successfully',
        );
    }

    public function balanceSheet(MultiPeriodReportRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->successResponse(
            $this->service->balanceSheet($data['periods'], $this->dimensions($data)),
            'Multi-period balance sheet report retrieved successfully',
        );
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{department_id?:int|null, project_id?:int|null}
     */
    private function dimensions(array $data): array
    {
        return [
            'department_id' => $data['department_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
        ];
    }
}
