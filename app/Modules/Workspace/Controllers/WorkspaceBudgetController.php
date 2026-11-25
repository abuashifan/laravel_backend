<?php

namespace App\Modules\Workspace\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Workspace\Requests\DecideWorkspaceBudgetRequest;
use App\Modules\Workspace\Requests\SaveWorkspaceBudgetRequest;
use App\Modules\Workspace\Requests\StoreWorkspaceBudgetPeriodRequest;
use App\Modules\Workspace\Services\WorkspaceBudgetService;
use App\Shared\Api\ApiResponse;
use App\Shared\Budget\BudgetSourceService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceBudgetController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly WorkspaceBudgetService $budgets,
        private readonly BudgetSourceService $sources,
        private readonly TenantContext $context,
    ) {}

    public function periods(): JsonResponse
    {
        return $this->successResponse($this->sources->periods($this->context->companyId())->orderByDesc('period_from')
            ->get(['id', 'name', 'period_from', 'period_to']));
    }

    public function createPeriod(StoreWorkspaceBudgetPeriodRequest $request): JsonResponse
    {
        return $this->successResponse($this->sources->createWorkspacePeriod(
            $this->context->companyId(), $request->validated('name'), $request->validated('period_from'), $request->user()->id,
        ), 'Periode Workspace ditetapkan.', 201);
    }

    public function units(Request $request): JsonResponse
    {
        $kind = $request->validate(['kind' => ['required', 'in:annual,activity']])['kind'];

        return $this->successResponse($this->budgets->units($kind));
    }

    public function items(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:annual,activity'],
            'period_id' => ['required', 'integer', 'min:1'],
            'unit_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->successResponse($this->budgets->items($data['kind'], $data['period_id'], $data['unit_id']));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'kind' => ['required', 'in:annual,activity'],
            'period_id' => ['required', 'integer', 'min:1'],
            'unit_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'in:draft,submitted,approved,rejected'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:25,50,100'],
        ]);

        return $this->successResponse($this->budgets->index($filters));
    }

    public function show(int $document): JsonResponse
    {
        return $this->successResponse($this->budgets->show($document));
    }

    public function store(SaveWorkspaceBudgetRequest $request): JsonResponse
    {
        return $this->successResponse($this->budgets->create($request->validated(), $request->user()->id), 'Draf dibuat.', 201);
    }

    public function update(SaveWorkspaceBudgetRequest $request, int $document): JsonResponse
    {
        return $this->successResponse($this->budgets->update($document, $request->validated()));
    }

    public function submit(Request $request, int $document): JsonResponse
    {
        return $this->successResponse($this->budgets->submit($document, $request->user()->id));
    }

    public function decide(DecideWorkspaceBudgetRequest $request, int $document, string $decision): JsonResponse
    {
        return $this->successResponse($this->budgets->decide($document, $decision, $request->validated('note'), $request->user()->id));
    }

    public function revise(Request $request, int $document): JsonResponse
    {
        return $this->successResponse($this->budgets->revise($document, $request->user()->id), 'Draf revisi dibuat.', 201);
    }
}
