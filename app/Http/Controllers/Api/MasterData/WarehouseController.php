<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreWarehouseRequest;
use App\Http\Requests\MasterData\UpdateWarehouseRequest;
use App\Models\Tenant\Warehouse;
use App\Services\MasterData\WarehouseService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WarehouseService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());
        return $this->listResponse($items, $request, 'Warehouses retrieved successfully');
    }

    public function store(StoreWarehouseRequest $request): JsonResponse
    {
        $warehouse = $this->service->create($request->validated());
        return $this->successResponse($warehouse, 'Warehouse created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        return $this->successResponse($warehouse, 'Warehouse retrieved successfully');
    }

    public function update(UpdateWarehouseRequest $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        $warehouse = $this->service->update($warehouse, $request->validated());

        return $this->successResponse($warehouse, 'Warehouse updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        $warehouse = $this->service->deactivate($warehouse);

        return $this->successResponse($warehouse, 'Warehouse deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        $warehouse = $this->service->activate($warehouse);

        return $this->successResponse($warehouse, 'Warehouse activated successfully');
    }
}

