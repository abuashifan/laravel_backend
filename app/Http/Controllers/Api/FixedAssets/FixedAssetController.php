<?php

namespace App\Http\Controllers\Api\FixedAssets;

use App\Http\Controllers\Controller;
use App\Http\Requests\FixedAssets\CapitalizeFixedAssetRequest;
use App\Http\Requests\FixedAssets\DisposeFixedAssetRequest;
use App\Http\Requests\FixedAssets\StoreFixedAssetRequest;
use App\Http\Requests\FixedAssets\UpdateFixedAssetRequest;
use App\Models\Tenant\FixedAsset;
use App\Services\FixedAssets\FixedAssetService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FixedAssetService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->service->list($request->query()), 'Fixed assets retrieved successfully');
    }

    public function store(StoreFixedAssetRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Fixed asset created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Fixed asset retrieved successfully');
    }

    public function update(UpdateFixedAssetRequest $request, int $id): JsonResponse
    {
        $asset = FixedAsset::query()->findOrFail($id);
        return $this->successResponse($this->service->update($asset, $request->validated()), 'Fixed asset updated successfully');
    }

    public function capitalize(CapitalizeFixedAssetRequest $request, int $id): JsonResponse
    {
        $asset = FixedAsset::query()->with('category')->findOrFail($id);
        return $this->successResponse($this->service->capitalize($asset, $request->validated()), 'Fixed asset capitalized successfully');
    }

    public function dispose(DisposeFixedAssetRequest $request, int $id): JsonResponse
    {
        $asset = FixedAsset::query()->with('category')->findOrFail($id);
        return $this->successResponse($this->service->dispose($asset, $request->validated()), 'Fixed asset disposed successfully');
    }
}
