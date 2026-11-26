<?php

namespace App\Modules\CashBank\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\Requests\WorkspaceSettlementRequest;
use App\Modules\CashBank\Requests\WorkspaceTrancheRequest;
use App\Modules\CashBank\Requests\WorkspaceTrancheReviewRequest;
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
            'state' => ['sometimes', 'in:'.implode(',', WorkspaceDisbursementService::STATES)],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'in:25,50,100'],
        ])));
    }

    public function options(Request $request, WorkspaceDisbursementOptions $options, PermissionService $permissions): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:cash,expense,advance,department,project'],
            'q' => ['nullable', 'string', 'max:120'],
        ]);
        $allowed = $data['type'] === 'cash'
            ? $permissions->can('cash_bank.workspace_disbursements.create') || $permissions->can('cash_bank.workspace_disbursements.approve')
            : $permissions->can('cash_bank.workspace_disbursements.approve');
        abort_unless($allowed, 403);

        return $this->successResponse($options->search($data['type'], trim($data['q'] ?? '')));
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->disbursements->show($id));
    }

    public function storeTranche(WorkspaceTrancheRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->disbursements->recordTranche($id, $request->validated(), $request->user()->id), 'Tahap pencairan dicatat.', 201);
    }

    public function updateTranche(WorkspaceTrancheRequest $request, int $id, int $tranche): JsonResponse
    {
        return $this->successResponse($this->disbursements->updateTranche($id, $tranche, $request->validated(), $request->user()->id));
    }

    public function cancelTranche(Request $request, int $id, int $tranche): JsonResponse
    {
        $note = (string) $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Masukkan alasan.'])['note'];

        return $this->successResponse($this->disbursements->cancelTranche($id, $tranche, $note, $request->user()->id));
    }

    public function review(WorkspaceTrancheReviewRequest $request, int $id, int $tranche, string $decision): JsonResponse
    {
        return $this->successResponse($this->disbursements->review($id, $tranche, $decision, $request->validated(), $request->user()->id));
    }

    public function reopen(Request $request, int $id, int $tranche): JsonResponse
    {
        $note = (string) $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Masukkan alasan.'])['note'];

        return $this->successResponse($this->disbursements->reopen($id, $tranche, $note, $request->user()->id));
    }

    public function settle(WorkspaceSettlementRequest $request, int $id, int $tranche): JsonResponse
    {
        return $this->successResponse($this->disbursements->settle($id, $tranche, $request->validated(), $request->user()->id));
    }

    public function cancelSettlement(Request $request, int $id, int $tranche): JsonResponse
    {
        $note = (string) $request->validate(['note' => ['required', 'string', 'max:1000']], ['note.required' => 'Masukkan alasan.'])['note'];

        return $this->successResponse($this->disbursements->cancelSettlement($id, $tranche, $note, $request->user()->id));
    }
}
