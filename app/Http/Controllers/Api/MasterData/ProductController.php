<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreProductRequest;
use App\Http\Requests\MasterData\UpdateProductRequest;
use App\Models\Tenant\Product;
use App\Services\MasterData\ProductService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProductService $service) {}

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());

        return $this->listResponse($items, $request, 'Products retrieved successfully');
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->service->create($request->validated());

        return $this->successResponse($product, 'Product created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $product = Product::query()
            ->with(['category', 'unit', 'salesAccount', 'purchaseAccount', 'inventoryAccount', 'cogsAccount'])
            ->findOrFail($id);

        return $this->successResponse($product, 'Product retrieved successfully');
    }

    public function update(UpdateProductRequest $request, int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $product = $this->service->update($product, $request->validated());

        return $this->successResponse($product, 'Product updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $product = $this->service->deactivate($product);

        return $this->successResponse($product, 'Product deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $product = Product::query()->findOrFail($id);
        $product = $this->service->activate($product);

        return $this->successResponse($product, 'Product activated successfully');
    }
}
