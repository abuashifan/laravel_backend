<?php

namespace App\Http\Controllers\Api\Access;

use App\Http\Controllers\Controller;
use App\Services\Permissions\PermissionCatalogService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class PermissionCatalogController extends Controller
{
    use ApiResponse;

    public function __invoke(PermissionCatalogService $catalogService): JsonResponse
    {
        return $this->successResponse($catalogService->grouped(), 'Permission catalog retrieved.');
    }
}
