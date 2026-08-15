<?php

namespace App\Modules\Inventory\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Services\BudgetWarningService;
use App\Modules\Budget\Support\CollectsBudgetWarnings;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Requests\StoreStockMovementRequest;
use App\Modules\Inventory\Requests\VoidStockMovementRequest;
use App\Modules\Inventory\Services\InventoryAccountMappingService;
use App\Modules\Inventory\Services\StockMovementService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    use ApiResponse;
    use CollectsBudgetWarnings;
    use ResolvesAdjacentRecords;

    public function __construct(
        private readonly StockMovementService $service,
        private readonly BudgetWarningService $budgetWarning,
        private readonly InventoryAccountMappingService $accountMapping,
        private readonly TenantContext $tenantContext,
    ) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(StockMovement::query(), $request, 'movement_number');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Stock movements retrieved successfully');
    }

    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->createDraft($request->validated()), 'Stock movement draft created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Stock movement retrieved successfully');
    }

    public function post(int $id): JsonResponse
    {
        $movement = $this->service->post(StockMovement::query()->findOrFail($id));

        return $this->successResponse(
            $movement,
            'Stock movement posted successfully',
            200,
            ['warnings' => $this->collectStockMovementBudgetWarnings($movement)],
        );
    }

    public function void(VoidStockMovementRequest $request, int $id): JsonResponse
    {
        $movement = StockMovement::query()->findOrFail($id);

        return $this->successResponse($this->service->void($movement, $request->validated('reason')), 'Stock movement voided successfully');
    }

    /**
     * Gap B — hanya untuk `sales_out` (COGS keluar saat penjualan). Jenis
     * movement lain (adjustment/opname/purchase_in/dst.) menuju akun
     * gain/loss yang di-mapping tingkat perusahaan (bukan per baris) lewat
     * `StockMovementJournalService`; menduplikasi resolusinya di sini berisiko
     * berbeda hasil dari jurnal yang sesungguhnya diposting. COGS adalah
     * dampak biaya yang paling langsung dan paling sering — cakupan lain bisa
     * menyusul kalau dibutuhkan.
     *
     * Dibaca dari baris `StockMovement` sendiri (`cogs_account_id`,
     * `department_id`, `project_id`), bukan dari baris jurnal — jurnal COGS
     * yang diposting `StockMovementJournalService::cogsDebitLines()` TIDAK
     * membawa dimensi (G11, belum tertutup untuk stock movement), padahal
     * kolomnya sudah ada di `stock_movement_lines` sejak awal.
     *
     * **Konsekuensi nyata G11 di sini, ditemukan lewat test**: karena baris
     * `check()` tetap membaca `actual` dari jurnal (`BudgetActualService::sumFor()`,
     * yang MEWAJIBKAN `jel.department_id` sama persis dengan departemen baris
     * anggaran begitu departemen itu bukan NULL — lihat komentar G7/G8 di
     * `BudgetWarningService::check()`), dan jurnal COGS tidak pernah membawa
     * `department_id`, **peringatan untuk pagu COGS yang di-scope ke satu
     * departemen tidak akan pernah menyala** — `actual` yang terbaca akan
     * selalu 0 untuk kombinasi itu, walau COGS sungguhan sudah jauh melebihi
     * pagu. Peringatan HANYA berfungsi untuk pagu COGS **tingkat perusahaan**
     * (baris anggaran dengan `department_id` NULL), karena jalur itu tidak
     * memasang filter departemen sama sekali. Menutup G11 untuk
     * `StockMovementJournalService` akan otomatis memperbaiki ini — tidak perlu
     * perubahan apa pun di sini.
     *
     * @return list<array<string,mixed>>
     */
    private function collectStockMovementBudgetWarnings(StockMovement $movement): array
    {
        if ($movement->movement_type !== 'sales_out') {
            return [];
        }

        $company = $this->tenantContext->company();
        if (! $company) {
            return [];
        }

        $movement->loadMissing('lines');
        $fallbackCogsAccount = $this->accountMapping->getCogsAccount();

        return $this->collectBudgetWarningsFor(
            $this->budgetWarning,
            $company->id,
            $movement->lines->map(fn ($line) => [
                'account_id' => $line->cogs_account_id ?: $fallbackCogsAccount,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'amount' => (float) $line->total_cost,
            ])->all(),
            $movement->movement_date?->format('Y-m') ?? '',
            postHoc: true,
        );
    }
}
