<?php

namespace App\Http\Controllers\Api\FixedAssets;

use App\Http\Controllers\Controller;
use App\Services\FixedAssets\FixedAssetReportService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

        $periodFrom = (string) $request->query('period_from', '');
        $periodTo = (string) $request->query('period_to');
        if ($periodFrom !== '' && $periodFrom > $periodTo) {
            throw ValidationException::withMessages([
                'period_from' => ['Period from must be before or equal to period to.'],
            ]);
        }

        return $this->successResponse($this->service->depreciation(
            $periodFrom !== '' ? $periodFrom : null,
            $periodTo,
            (string) $request->query('mode', 'detail'),
        ), 'Fixed asset depreciation report retrieved successfully');
    }

    public function disposals(Request $request): JsonResponse
    {
        $request->validate([
            'disposal_date_from' => ['nullable', 'date_format:Y-m-d'],
            'disposal_date_to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $dateFrom = (string) $request->query('disposal_date_from', '');
        $dateTo = (string) $request->query('disposal_date_to', '');
        if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
            throw ValidationException::withMessages([
                'disposal_date_from' => ['Disposal date from must be before or equal to disposal date to.'],
            ]);
        }

        return $this->successResponse($this->service->disposals($request->query()), 'Fixed asset disposal report retrieved successfully');
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $request->validate(['as_of_period' => ['required', 'date_format:Y-m']]);
        return $this->successResponse($this->service->reconciliation((string) $request->query('as_of_period')), 'Fixed asset reconciliation report retrieved successfully');
    }
}
