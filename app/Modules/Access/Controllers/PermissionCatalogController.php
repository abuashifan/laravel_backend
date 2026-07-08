<?php

namespace App\Modules\Access\Controllers;

use App\Http\Controllers\Controller;
use App\Shared\Api\ApiResponse;
use App\Shared\Permission\PermissionCatalogService;
use Illuminate\Http\JsonResponse;

class PermissionCatalogController extends Controller
{
    use ApiResponse;

    public function __invoke(PermissionCatalogService $catalogService): JsonResponse
    {
        return $this->successResponse($catalogService->grouped(), 'Permission catalog retrieved.');
    }
}
