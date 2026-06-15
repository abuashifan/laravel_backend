<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Http\Requests\Accounting\PeriodEndPeriodRequest;
use App\Http\Requests\Accounting\ReopenPeriodEndRequest;
use App\Services\Accounting\PeriodEndService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PeriodEndController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly PeriodEndService $service)
    {
    }

    public function status(PeriodEndPeriodRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->status($request->period()), 'Period-end status retrieved successfully');
    }

    public function checklist(PeriodEndPeriodRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->checklist($request->period()), 'Period-end checklist retrieved successfully');
    }

    public function run(PeriodEndPeriodRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->run($request->period()), 'Period-end run completed successfully');
    }

    public function reopen(ReopenPeriodEndRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->reopen($request->period(), (string) $request->validated('reason')), 'Period-end reopened successfully');
    }
}
