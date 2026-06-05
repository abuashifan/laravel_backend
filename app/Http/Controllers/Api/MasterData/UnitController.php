<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreUnitRequest;
use App\Http\Requests\MasterData\UpdateUnitRequest;
use App\Models\Tenant\Unit;
use App\Services\MasterData\UnitService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly UnitService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());
        return $this->listResponse($items, $request, 'Units retrieved successfully');
    }

    public function store(StoreUnitRequest $request): JsonResponse
    {
        $unit = $this->service->create($request->validated());
        return $this->successResponse($unit, 'Unit created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $unit = Unit::query()->findOrFail($id);
        return $this->successResponse($unit, 'Unit retrieved successfully');
    }

    public function update(UpdateUnitRequest $request, int $id): JsonResponse
    {
        $unit = Unit::query()->findOrFail($id);
        $unit = $this->service->update($unit, $request->validated());

        return $this->successResponse($unit, 'Unit updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $unit = Unit::query()->findOrFail($id);
        $unit = $this->service->deactivate($unit);

        return $this->successResponse($unit, 'Unit deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $unit = Unit::query()->findOrFail($id);
        $unit = $this->service->activate($unit);

        return $this->successResponse($unit, 'Unit activated successfully');
    }
}

