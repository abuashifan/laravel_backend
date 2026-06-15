<?php

namespace App\Http\Controllers\Api\FixedAssets;

use App\Http\Controllers\Controller;
use App\Services\FixedAssets\FixedAssetReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FixedAssetReportService $service)
    {
    }

    public function register(Request $request): JsonResponse
    {
        $request->validate(['as_of_period' => ['required', 'date_format:Y-m']]);
        return $this->successResponse($this->service->register((string) $request->query('as_of_period')), 'Fixed asset register report retrieved successfully');
    }

    public function depreciation(Request $request): JsonResponse
    {
        $request->validate([
            'period_from' => ['nullable', 'date_format:Y-m'],
            'period_to' => ['required', 'date_format:Y-m'],
            'mode' => ['nullable', 'in:detail,yearly_summary'],
        ]);
        return $this->successResponse($this->service->depreciation(
            $request->query('period_from') ? (string) $request->query('period_from') : null,
            (string) $request->query('period_to'),
            (string) $request->query('mode', 'detail'),
        ), 'Fixed asset depreciation report retrieved successfully');
    }

    public function disposals(Request $request): JsonResponse
    {
        $request->validate([
            'disposal_date_from' => ['nullable', 'date_format:Y-m-d'],
            'disposal_date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        return $this->successResponse($this->service->disposals($request->query()), 'Fixed asset disposal report retrieved successfully');
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $request->validate(['as_of_period' => ['required', 'date_format:Y-m']]);
        return $this->successResponse($this->service->reconciliation((string) $request->query('as_of_period')), 'Fixed asset reconciliation report retrieved successfully');
    }
}
