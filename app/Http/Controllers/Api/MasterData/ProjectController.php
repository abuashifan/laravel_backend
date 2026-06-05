<?php

namespace App\Http\Controllers\Api\MasterData;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreProjectRequest;
use App\Http\Requests\MasterData\UpdateProjectRequest;
use App\Models\Tenant\Project;
use App\Services\MasterData\ProjectService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ProjectService $service)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $items = $this->service->list($request->query());
        return $this->listResponse($items, $request, 'Projects retrieved successfully');
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->service->create($request->validated());
        return $this->successResponse($project, 'Project created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        return $this->successResponse($project, 'Project retrieved successfully');
    }

    public function update(UpdateProjectRequest $request, int $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        $project = $this->service->update($project, $request->validated());

        return $this->successResponse($project, 'Project updated successfully');
    }

    public function deactivate(int $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        $project = $this->service->deactivate($project);

        return $this->successResponse($project, 'Project deactivated successfully');
    }

    public function activate(int $id): JsonResponse
    {
        $project = Project::query()->findOrFail($id);
        $project = $this->service->activate($project);

        return $this->successResponse($project, 'Project activated successfully');
    }
}

