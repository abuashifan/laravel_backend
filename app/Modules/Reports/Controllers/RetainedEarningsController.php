<?php

namespace App\Modules\Reports\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reports\Requests\RetainedEarningsRequest;
use App\Modules\Reports\Services\RetainedEarningsService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class RetainedEarningsController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly RetainedEarningsService $service) {}

    public function index(RetainedEarningsRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->getReport($request->validated()), 'Retained earnings report retrieved successfully');
    }
}
