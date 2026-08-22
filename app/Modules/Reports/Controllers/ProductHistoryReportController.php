<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\ProductHistoryReportRequest;
use App\Modules\Reports\Services\ProductHistoryReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class ProductHistoryReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductHistoryReportService $service) {}

    public function index(ProductHistoryReportRequest $request): JsonResponse
    {
        $result = $this->service->getReport($request->validated());

        return $this->successResponse($result, 'Product history retrieved successfully');
    }
}
