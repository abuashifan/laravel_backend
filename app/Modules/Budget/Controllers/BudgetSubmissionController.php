<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Requests\BudgetApprovalRequest;
use App\Modules\Budget\Requests\StoreBudgetSubmissionRequest;
use App\Modules\Budget\Requests\UpdateBudgetLinesRequest;
use App\Modules\Budget\Requests\UpdateBudgetSubmissionRequest;
use App\Modules\Budget\Services\BudgetPeriodService;
use App\Modules\Budget\Services\BudgetSubmissionService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BudgetSubmissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BudgetSubmissionService $service,
        private readonly BudgetPeriodService $periodService,
    ) {}

    public function index(int $periodId, Request $request): JsonResponse
    {
        $period = $this->periodService->find($periodId);
        $submissions = $this->service->list($period, $request->query());

        return $this->successResponse($submissions, 'Budget submissions retrieved successfully');
    }

    public function store(StoreBudgetSubmissionRequest $request, int $periodId): JsonResponse
    {
        $period = $this->periodService->find($periodId);
        $submission = $this->service->create($period, $request->validated());

        return $this->successResponse($submission, 'Budget submission created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        $submission = $this->service->find($id);

        return $this->successResponse($submission, 'Budget submission retrieved successfully');
    }

    public function update(UpdateBudgetSubmissionRequest $request, int $id): JsonResponse
    {
        $submission = $this->service->find($id);
        $submission = $this->service->update($submission, $request->validated());

        return $this->successResponse($submission, 'Budget submission updated successfully');
    }

    public function updateLines(UpdateBudgetLinesRequest $request, int $id): JsonResponse
    {
        $submission = $this->service->find($id);
        $submission = $this->service->updateLines($submission, $request->validated()['lines']);

        return $this->successResponse($submission, 'Budget lines updated successfully');
    }

    public function submit(int $id): JsonResponse
    {
        $submission = $this->service->find($id);
        $submission = $this->service->submit($submission);

        return $this->successResponse($submission, 'Budget submission submitted successfully');
    }

    public function approveHead(int $id): JsonResponse
    {
        $submission = $this->service->find($id);
        $submission = $this->service->approveHead($submission);

        return $this->successResponse($submission, 'Budget submission approved by head');
    }

    public function approveFinance(int $id): JsonResponse
    {
        $submission = $this->service->find($id);
        $submission = $this->service->approveFinance($submission);

        return $this->successResponse($submission, 'Budget submission approved by finance');
    }

    public function reject(BudgetApprovalRequest $request, int $id): JsonResponse
    {
        $submission = $this->service->find($id);
        $submission = $this->service->reject($submission, $request->validated()['rejection_note']);

        return $this->successResponse($submission, 'Budget submission rejected');
    }
}
