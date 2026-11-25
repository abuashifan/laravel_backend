<?php

namespace App\Modules\CashBank\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CashBank\Requests\RecordWorkspaceDisbursementRequest;
use App\Modules\CashBank\Requests\ReviewWorkspaceDisbursementRequest;
use App\Modules\CashBank\Services\WorkspaceDisbursementService;
use App\Shared\Api\ApiResponse;
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
        ])));
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
