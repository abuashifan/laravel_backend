<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\JournalListReportRequest;
use App\Modules\Reports\Services\JournalListReportService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class JournalListReportController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly JournalListReportService $service) {}

    public function index(JournalListReportRequest $request): JsonResponse
    {
        $result = $this->service->getReport($request->validated());

        return $this->successResponse($result, 'Journal list retrieved successfully');
    }
}
