<?php

namespace App\Modules\FixedAssets\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FixedAssets\Models\FixedAsset;
use App\Modules\FixedAssets\Requests\CapitalizeFixedAssetRequest;
use App\Modules\FixedAssets\Requests\DisposeFixedAssetRequest;
use App\Modules\FixedAssets\Requests\StoreFixedAssetRequest;
use App\Modules\FixedAssets\Requests\UpdateFixedAssetRequest;
use App\Modules\FixedAssets\Services\FixedAssetService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FixedAssetController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly FixedAssetService $service) {}

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
