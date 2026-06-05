<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreProductCategoryRequest;
use App\Http\Requests\MasterData\UpdateProductCategoryRequest;
use App\Models\Tenant\ProductCategory;
use App\Services\MasterData\ProductCategoryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductCategoryService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());
        return $this->listResponse($items, $request, 'Product categories retrieved successfully');
    }

    public function store(StoreProductCategoryRequest $request): JsonResponse
    {
        $category = $this->service->create($request->validated());
        return $this->successResponse($category, 'Product category created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $category = ProductCategory::query()->findOrFail($id);
        return $this->successResponse($category, 'Product category retrieved successfully');
    }

    public function update(UpdateProductCategoryRequest $request, int $id): JsonResponse
    {
        $category = ProductCategory::query()->findOrFail($id);
        $category = $this->service->update($category, $request->validated());

        return $this->successResponse($category, 'Product category updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $category = ProductCategory::query()->findOrFail($id);
        $category = $this->service->deactivate($category);

        return $this->successResponse($category, 'Product category deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $category = ProductCategory::query()->findOrFail($id);
        $category = $this->service->activate($category);

        return $this->successResponse($category, 'Product category activated successfully');
    }
}

