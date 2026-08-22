<?php

namespace App\Modules\CashBank\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Services\BudgetWarningService;
use App\Modules\Budget\Support\CollectsBudgetWarnings;
use App\Modules\CashBank\Models\CashPayment;
use App\Modules\CashBank\Requests\CashBankActionRequest;
use App\Modules\CashBank\Requests\StoreCashPaymentRequest;
use App\Modules\CashBank\Services\CashPaymentService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashPaymentController extends Controller
{
    use ApiResponse;
    use CollectsBudgetWarnings;
    use ResolvesAdjacentRecords;

    public function __construct(
        private readonly CashPaymentService $service,
        private readonly BudgetWarningService $budgetWarning,
        private readonly TenantContext $tenantContext,
    ) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(CashPayment::query(), $request, 'payment_number');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Cash payments retrieved successfully');
    }

    public function store(StoreCashPaymentRequest $request): JsonResponse
    {
        return $this->successResponse($this->service->create($request->validated()), 'Cash payment created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Cash payment retrieved successfully');
    }

    public function post(int $id): JsonResponse
    {
        $payment = $this->service->post(CashPayment::query()->findOrFail($id));

        return $this->successResponse(
            $payment,
            'Cash payment posted successfully',
            200,
            ['warnings' => $this->collectCashPaymentBudgetWarnings($payment)],
        );
    }

    public function void(CashBankActionRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->void(CashPayment::query()->findOrFail($id), $request->validated('reason')), 'Cash payment voided successfully');
    }

    /**
     * Gap B — pola yang sama dengan `JournalEntryController::collectBudgetWarnings()`.
     * Baris cash payment sudah membawa `department_id`/`project_id` sejak awal
     * (tidak seperti Purchase Bill), jadi tidak perlu penyesuaian tambahan.
     *
     * `postHoc: true` — dipanggil SETELAH `$this->service->post()`, jadi jurnalnya
     * sudah ada di ledger dan `actual` sudah memuat pembayaran ini. Lihat
     * `CollectsBudgetWarnings` untuk penjelasan lengkap kenapa ini beda dari
     * Journal (yang tidak dikoreksi).
     *
     * @return list<array<string,mixed>>
     */
    private function collectCashPaymentBudgetWarnings(CashPayment $payment): array
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return [];
        }

        $payment->loadMissing('lines');

        return $this->collectBudgetWarningsFor(
            $this->budgetWarning,
            $company->id,
            $payment->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'amount' => (float) $line->amount,
            ])->all(),
            $payment->payment_date?->format('Y-m') ?? '',
            postHoc: true,
        );
    }
}
