<?php

namespace App\Modules\FixedAssets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAssets\Requests\StoreFixedAssetCategoryRequest;
use App\Modules\FixedAssets\Requests\UpdateFixedAssetCategoryRequest;
use App\Models\Tenant\FixedAssetCategory;
use App\Modules\FixedAssets\Services\FixedAssetService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FixedAssetService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->service->categories($request->query()), 'Fixed asset categories retrieved successfully');
    }

    public function store(StoreFixedAssetCategoryRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->createCategory($request->validated()), 'Fixed asset category created successfully', 201);
    }

    public function update(UpdateFixedAssetCategoryRequest $request, int $id): JsonResponse
    {
        $category = FixedAssetCategory::query()->findOrFail($id);
        return $this->successResponse($this->service->updateCategory($category, $request->validated()), 'Fixed asset category updated successfully');
    }
}
