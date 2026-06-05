<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreDepartmentRequest;
use App\Http\Requests\MasterData\UpdateDepartmentRequest;
use App\Models\Tenant\Department;
use App\Services\MasterData\DepartmentService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DepartmentService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());
        return $this->listResponse($items, $request, 'Departments retrieved successfully');
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = $this->service->create($request->validated());
        return $this->successResponse($department, 'Department created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $department = Department::query()->findOrFail($id);
        return $this->successResponse($department, 'Department retrieved successfully');
    }

    public function update(UpdateDepartmentRequest $request, int $id): JsonResponse
    {
        $department = Department::query()->findOrFail($id);
        $department = $this->service->update($department, $request->validated());

        return $this->successResponse($department, 'Department updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $department = Department::query()->findOrFail($id);
        $department = $this->service->deactivate($department);

        return $this->successResponse($department, 'Department deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $department = Department::query()->findOrFail($id);
        $department = $this->service->activate($department);

        return $this->successResponse($department, 'Department activated successfully');
    }
}

