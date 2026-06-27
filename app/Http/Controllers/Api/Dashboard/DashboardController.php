<?php

namespace App\Http\Controllers\Api\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\DashboardService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DashboardService $service)
    {
    }

    public function summary(): JsonResponse
    {
        return $this->successResponse($this->service->summary(), 'Dashboard summary retrieved successfully');
    }

    public function pending(): JsonResponse
    {
        return $this->successResponse($this->service->pending(), 'Dashboard pending items retrieved successfully');
    }

    public function chart(): JsonResponse
    {
        return $this->successResponse($this->service->chartData(), 'Dashboard chart data retrieved successfully');
    }

    public function activities(): JsonResponse
    {
        return $this->successResponse($this->service->recentActivities(), 'Dashboard activities retrieved successfully');
    }
}