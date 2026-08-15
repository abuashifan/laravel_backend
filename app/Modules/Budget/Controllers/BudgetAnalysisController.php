<?php

namespace App\Modules\Budget\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Requests\BudgetAnalysisRequest;
use App\Modules\Budget\Services\BudgetAnalysisService;
use App\Shared\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Sembilan dari enam belas reporting view dilayani controller ini. Semuanya
 * **preset** di atas satu service — kalau ada view yang butuh logika sendiri di
 * sini, itu tanda mesin fase 2 belum lengkap, bukan alasan menulis query baru.
 *
 * Endpoint terpisah tetap dibuat meski isinya preset karena
 * `frontend/src/modules/reports/constants/reportCategories.ts` butuh report key
 * diskrit untuk katalog laporan dan fitur simpan laporan.
 */
class BudgetAnalysisController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly BudgetAnalysisService $service) {}

    public function analysis(BudgetAnalysisRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->service->analyze($request->validated()),
            'Budget analysis retrieved successfully',
        );
    }

    /** Ringkasan tanpa dimensi — satu baris total. */
    public function summary(BudgetAnalysisRequest $request): JsonResponse
    {
        return $this->successResponse(
            $this->service->analyze($request->validated() + ['group_by' => []]),
            'Budget summary retrieved successfully',
        );
    }

    public function byAccount(BudgetAnalysisRequest $request): JsonResponse
    {
        return $this->preset($request, ['account']);
    }

    public function byCostCenter(BudgetAnalysisRequest $request): JsonResponse
    {
        return $this->preset($request, ['department']);
    }

    public function byProject(BudgetAnalysisRequest $request): JsonResponse
    {
        return $this->preset($request, ['project']);
    }

    public function byPeriod(BudgetAnalysisRequest $request): JsonResponse
    {
        return $this->preset($request, ['period']);
    }

    /** Sama dengan by-account, diurutkan dari penyimpangan terbesar. */
    public function variance(BudgetAnalysisRequest $request): JsonResponse
    {
        $result = $this->service->analyze(array_merge($request->validated(), [
            'group_by' => ['account'],
            'mode' => 'variance',
        ]));

        $result['rows'] = $this->sortedBy($result['rows'], fn (array $row) => abs((float) $row['variance']));

        return $this->successResponse($result, 'Budget variance retrieved successfully');
    }

    /** Sama, diurutkan dari serapan tertinggi. Baris tanpa anggaran ditaruh terakhir. */
    public function utilization(BudgetAnalysisRequest $request): JsonResponse
    {
        $result = $this->service->analyze(array_merge($request->validated(), ['group_by' => ['account']]));

        $result['rows'] = $this->sortedBy($result['rows'], fn (array $row) => $row['utilization_pct'] ?? -1);

        return $this->successResponse($result, 'Budget utilization retrieved successfully');
    }

    /**
     * @param  array<int,string>  $groupBy
     */
    private function preset(BudgetAnalysisRequest $request, array $groupBy): JsonResponse
    {
        return $this->successResponse(
            $this->service->analyze(array_merge($request->validated(), ['group_by' => $groupBy])),
            'Budget report retrieved successfully',
        );
    }

    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    private function sortedBy(array $rows, callable $key): array
    {
        usort($rows, fn (array $a, array $b) => $key($b) <=> $key($a));

        return $rows;
    }
}
