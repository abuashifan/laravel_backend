<?php

namespace App\Http\Controllers\Api\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\AccountMappingHealthService;
use App\Support\Api\ApiResponseBuilder;
use Illuminate\Http\JsonResponse;

class AccountMappingHealthController extends Controller
{
    public function __construct(private readonly AccountMappingHealthService $service)
    {
    }

    public function index(): JsonResponse
    {
        return ApiResponseBuilder::success(
            $this->service->check(),
            'Account mapping health check retrieved successfully'
        );
    }
}
