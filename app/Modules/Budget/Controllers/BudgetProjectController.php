<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Requests\BudgetCashRequest;
use App\Modules\Budget\Requests\BudgetProjectTransactionsRequest;
use App\Modules\Budget\Services\BudgetCashService;
use App\Modules\Budget\Services\BudgetProjectService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

class BudgetProjectController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly BudgetProjectService $service,
        private readonly BudgetCashService $cashService,
    ) {}

    public function summary(BudgetCashRequest $request, int $id): JsonResponse
    {
        return $this->successResponse(
            $this->service->summary($id, $request->validated()),
            'Project financial summary retrieved successfully',
        );
    }

    /**
     * Profitability adalah sudut pandang lain dari data yang sama — bukan
     * perhitungan kedua. Dipisah karena katalog laporan frontend butuh report key
     * tersendiri.
     */
    public function profitability(BudgetCashRequest $request, int $id): JsonResponse
    {
        $summary = $this->service->summary($id, $request->validated());

        return $this->successResponse([
            'project' => $summary['project'],
            'period' => $summary['period'],
            'budget' => $summary['budget'],
            'actual' => $summary['actual'],
            'variance' => $summary['variance'],
            'cost_utilization_pct' => $summary['cost_utilization_pct'],
            'meta' => $summary['meta'],
        ], 'Project profitability retrieved successfully');
    }

    /** Project Cash Flow = Cash Budget difilter proyek (view #15). */
    public function cashFlow(BudgetCashRequest $request, int $id): JsonResponse
    {
        return $this->successResponse(
            $this->cashService->summary($request->validated() + ['project_id' => $id]),
            'Project cash flow retrieved successfully',
        );
    }

    /** Daftar transaksi mentah yang menyusun Actual Revenue/Cost proyek ini. */
    public function transactions(BudgetProjectTransactionsRequest $request, int $id): JsonResponse
    {
        return $this->successResponse(
            $this->service->transactions($id, $request->validated()),
            'Project transactions retrieved successfully',
        );
    }
}
