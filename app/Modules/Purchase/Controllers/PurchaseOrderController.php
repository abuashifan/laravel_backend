<?php

namespace App\Modules\Purchase\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Services\BudgetWarningService;
use App\Modules\Budget\Support\CollectsBudgetWarnings;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseRequest;
use App\Modules\Purchase\Requests\PurchaseRequestActionRequest;
use App\Modules\Purchase\Requests\StorePurchaseOrderRequest;
use App\Modules\Purchase\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Purchase\Services\PurchaseOrderService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    use ApiResponse;
    use CollectsBudgetWarnings;
    use ResolvesAdjacentRecords;

    public function __construct(
        private readonly PurchaseOrderService $service,
        private readonly BudgetWarningService $budgetWarning,
        private readonly TenantContext $tenantContext,
    ) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(PurchaseOrder::query(), $request, 'order_number');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Purchase orders retrieved successfully');
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'purchase_request' && ! empty($data['source_id'])) {
            return $this->successResponse($this->service->createFromPurchaseRequest(PurchaseRequest::query()->findOrFail((int) $data['source_id']), $data), 'Purchase order created from purchase request successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Purchase order created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Purchase order retrieved successfully');
    }

    public function update(UpdatePurchaseOrderRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update(PurchaseOrder::query()->findOrFail($id), $request->validated()), 'Purchase order updated successfully');
    }

    public function createFromPurchaseRequest(Request $request, int $purchaseRequestId): JsonResponse
    {
        return $this->successResponse($this->service->createFromPurchaseRequest(PurchaseRequest::query()->findOrFail($purchaseRequestId), $request->all()), 'Purchase order created from purchase request successfully', 201);
    }

    public function approve(int $id): JsonResponse
    {
        $order = $this->service->approve(PurchaseOrder::query()->findOrFail($id));

        return $this->successResponse(
            $order,
            'Purchase order approved successfully',
            200,
            ['warnings' => $this->collectPurchaseOrderBudgetWarnings($order)],
        );
    }

    /**
     * Gap B — beda dari empat modul lain: PO tidak pernah posting jurnal (murni
     * dokumen komitmen), jadi tidak ada "amountToPost" dari jurnal sama sekali.
     * Persetujuan PO diperlakukan sebagai KOMITMEN terhadap anggaran — pagu
     * dianggap mulai terpakai begitu disetujui, bukan menunggu Bill diposting.
     * Ini peringatan dini yang disengaja lebih awal dari peringatan modul lain,
     * bukan cerminan realisasi/actual yang sesungguhnya sudah terjadi.
     *
     * Hanya baris dengan `expense_account_id` terisi yang dicek. Baris
     * stok/fixed-asset tidak punya akun laba-rugi (mereka menuju akun
     * Inventory/Fixed Asset Clearing di neraca lewat Vendor Bill, bukan lewat
     * PO) — mengecek budget terhadap akun neraca akan selalu kosong hasilnya,
     * jadi dilewati saja daripada memanggil `check()` yang pasti null.
     *
     * @return list<array<string,mixed>>
     */
    private function collectPurchaseOrderBudgetWarnings(PurchaseOrder $order): array
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return [];
        }

        $order->loadMissing('lines');

        return $this->collectBudgetWarningsFor(
            $this->budgetWarning,
            $company->id,
            $order->lines
                ->filter(fn ($line) => $line->expense_account_id !== null)
                ->map(fn ($line) => [
                    'account_id' => $line->expense_account_id,
                    'department_id' => $line->department_id,
                    'project_id' => $line->project_id,
                    'amount' => (float) $line->subtotal_after_discount,
                ])
                ->all(),
            $order->order_date?->format('Y-m') ?? '',
        );
    }

    public function confirm(int $id): JsonResponse
    {
        return $this->successResponse($this->service->confirm(PurchaseOrder::query()->findOrFail($id)), 'Purchase order confirmed successfully');
    }

    public function cancel(PurchaseRequestActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->cancel(PurchaseOrder::query()->findOrFail($id), $request->validated('reason')), 'Purchase order cancelled successfully');
    }

    public function close(int $id): JsonResponse
    {
        return $this->successResponse($this->service->close(PurchaseOrder::query()->findOrFail($id)), 'Purchase order closed successfully');
    }
}
