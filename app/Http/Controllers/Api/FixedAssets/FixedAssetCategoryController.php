<?php

namespace App\Http\Controllers\Api\FixedAssets;

use App\Http\Controllers\Controller;
use App\Http\Requests\FixedAssets\StoreFixedAssetCategoryRequest;
use App\Http\Requests\FixedAssets\UpdateFixedAssetCategoryRequest;
use App\Models\Tenant\FixedAssetCategory;
use App\Services\FixedAssets\FixedAssetService;
use App\Traits\ApiResponse;
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
