<?php

namespace App\Modules\CashBank\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\Requests\RecordWorkspaceDisbursementRequest;
use App\Modules\CashBank\Requests\ReviewWorkspaceDisbursementRequest;
use App\Modules\CashBank\Services\WorkspaceDisbursementOptions;
use App\Modules\CashBank\Services\WorkspaceDisbursementService;
use App\Shared\Api\ApiResponse;
use App\Shared\Permission\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceDisbursementController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly WorkspaceDisbursementService $disbursements) {}

    public function index(Request $request): JsonResponse
    {
        return $this->successResponse($this->disbursements->index($request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:25,50,100'],
        ])));
    }

    public function options(Request $request, WorkspaceDisbursementOptions $options, PermissionService $permissions): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:cash,expense,department,project'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $permission = $data['type'] === 'cash' ? 'cash_bank.workspace_disbursements.create' : 'cash_bank.workspace_disbursements.approve';
        abort_unless($permissions->can($permission), 403);

        return $this->successResponse($options->search($data['type'], trim($data['q'] ?? '')));
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->disbursements->show($id));
    }

    public function record(RecordWorkspaceDisbursementRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->disbursements->record($id, $request->validated(), $request->user()->id));
    }

    public function review(ReviewWorkspaceDisbursementRequest $request, int $id, string $decision): JsonResponse
    {
        return $this->successResponse($this->disbursements->review($id, $decision, $request->validated(), $request->user()->id));
    }

    public function reopen(ReviewWorkspaceDisbursementRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->disbursements->reopen($id, $request->validated('note'), $request->user()->id));
    }
}
