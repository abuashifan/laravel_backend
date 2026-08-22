<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Budget\Services\BudgetWarningService;
use App\Modules\Budget\Support\CollectsBudgetWarnings;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\ProformaInvoice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Requests\PostSalesInvoiceRequest;
use App\Modules\Sales\Requests\SalesActionRequest;
use App\Modules\Sales\Requests\StoreSalesInvoiceRequest;
use App\Modules\Sales\Requests\UpdateSalesInvoiceRequest;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use App\Shared\Export\ExcelExportService;
use App\Shared\Tenant\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesInvoiceController extends Controller
{
    use ApiResponse;
    use CollectsBudgetWarnings;
    use ResolvesAdjacentRecords;

    public function __construct(
        private readonly SalesInvoiceService $service,
        private readonly BudgetWarningService $budgetWarning,
        private readonly TenantContext $tenantContext,
    ) {}

    public function adjacent(Request $request): JsonResponse
    {
        return $this->adjacentResponse(SalesInvoice::query(), $request, 'invoice_number');
    }

    public function index(Request $request): JsonResponse
    {
        return $this->listResponse($this->service->list($request->query()), $request, 'Sales invoices retrieved successfully');
    }

    public function store(StoreSalesInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (($data['source_type'] ?? null) === 'delivery_order' && ! empty($data['source_id'])) {
            $invoice = $this->service->createFromDeliveryOrder(DeliveryOrder::query()->findOrFail((int) $data['source_id']), $data);

            return $this->respondWithInvoice($invoice, 'Sales invoice created from delivery order successfully', 201);
        }

        return $this->respondWithInvoice($this->service->create($data), 'Sales invoice created successfully', 201);
    }

    public function show(int $id): JsonResponse
    {
        return $this->successResponse($this->service->find($id), 'Sales invoice retrieved successfully');
    }

    public function update(UpdateSalesInvoiceRequest $request, int $id): JsonResponse
    {
        return $this->successResponse($this->service->update(SalesInvoice::query()->findOrFail($id), $request->validated()), 'Sales invoice updated successfully');
    }

    public function createFromSalesOrder(Request $request, int $salesOrderId): JsonResponse
    {
        $invoice = $this->service->createFromSalesOrder(SalesOrder::query()->findOrFail($salesOrderId), $request->all());

        return $this->respondWithInvoice($invoice, 'Sales invoice created from sales order successfully', 201);
    }

    public function createFromDeliveryOrder(Request $request, int $deliveryOrderId): JsonResponse
    {
        $invoice = $this->service->createFromDeliveryOrder(DeliveryOrder::query()->findOrFail($deliveryOrderId), $request->all());

        return $this->respondWithInvoice($invoice, 'Sales invoice created from delivery order successfully', 201);
    }

    public function createFromProforma(Request $request, int $proformaId): JsonResponse
    {
        $invoice = $this->service->createFromProforma(ProformaInvoice::query()->findOrFail($proformaId), $request->all());

        return $this->respondWithInvoice($invoice, 'Sales invoice created from proforma successfully', 201);
    }

    public function approve(int $id): JsonResponse
    {
        return $this->successResponse($this->service->approve(SalesInvoice::query()->findOrFail($id)), 'Sales invoice approved successfully');
    }

    public function post(PostSalesInvoiceRequest $request, int $id): JsonResponse
    {
        $amount = $request->validated('applied_down_payment_amount');

        $invoice = $this->service->post(SalesInvoice::query()->findOrFail($id), $amount !== null ? (float) $amount : null);

        return $this->respondWithInvoice($invoice, 'Sales invoice posted successfully');
    }

    /**
     * Gap B — satu jalan keluar untuk semua aksi yang BISA menghasilkan invoice
     * berstatus posted: `post()` eksplisit, TAPI JUGA `create()`/`createFrom*()`
     * saat auto-post aktif (`shouldAutoPostOnCreateAccountingWorkflow()`
     * memanggil `post()` DI DALAM `create()`, bukan lewat controller). Tanpa
     * pengecekan status di sini, warning hanya muncul untuk company yang
     * approval-nya manual — yang auto-post (kemungkinan mayoritas) tidak akan
     * pernah melihatnya sama sekali walau invoice-nya benar-benar terposting.
     */
    private function respondWithInvoice(SalesInvoice $invoice, string $message, int $status = 200): JsonResponse
    {
        if ($invoice->status === 'draft') {
            return $this->successResponse($invoice, $message, $status);
        }

        return $this->successResponse($invoice, $message, $status, ['warnings' => $this->collectSalesInvoiceBudgetWarnings($invoice)]);
    }

    /**
     * Gap B — pola yang sama dengan `JournalEntryController::collectBudgetWarnings()`,
     * dari sisi pendapatan alih-alih beban.
     *
     * Dibaca dari `revenue_account_id` di BARIS INVOICE, bukan dari akun
     * terjurnal — `SalesInvoiceService::post()` sudah dimurnikan (fase 4
     * rencana budget) sehingga dimensi & akun revenue tersimpan persis di sini,
     * jadi tidak perlu membaca ulang dari jurnal yang baru diposting.
     *
     * `gross_amount` sudah positif (nilai jual, bukan debit/kredit) — beda dari
     * Journal yang menghitung tanda dari debit-kredit, di sini arahnya sudah
     * jelas: SETIAP baris invoice adalah pendapatan.
     *
     * `postHoc: true` — dipanggil setelah invoice ini SUDAH terposting (baik
     * lewat `post()` eksplisit maupun auto-post di dalam `create()`), jadi
     * `actual` yang dibaca `check()` sudah memuat invoice ini sendiri. Tanpa
     * koreksi ini, `new_total` akan dua kali lipat nilai sebenarnya untuk
     * transaksi pertama pada kombinasi akun+dimensi+bulan itu — lihat
     * `CollectsBudgetWarnings` untuk penjelasan lengkap.
     *
     * @return list<array<string,mixed>>
     */
    private function collectSalesInvoiceBudgetWarnings(SalesInvoice $invoice): array
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            return [];
        }

        $invoice->loadMissing('lines');

        return $this->collectBudgetWarningsFor(
            $this->budgetWarning,
            $company->id,
            $invoice->lines->map(fn ($line) => [
                'account_id' => $line->revenue_account_id,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'amount' => (float) $line->gross_amount,
            ])->all(),
            $invoice->invoice_date?->format('Y-m') ?? '',
            postHoc: true,
        );
    }

    public function void(SalesActionRequest $request, int $id): JsonResponse
    {
        $request->validated();

        return $this->successResponse($this->service->void(SalesInvoice::query()->findOrFail($id), $request->input('reason')), 'Sales invoice voided successfully');
    }

    /**
     * Posting massal faktur — lewat {@see SalesInvoiceService::bulkPost()}.
     *
     * Menerima daftar ID faktur atau import_batch_id (memposting semua
     * faktur yang dihasilkan dari satu batch impor). Faktur yang sudah
     * posted/void diabaikan oleh service.
     */
    public function bulkPost(Request $request): JsonResponse
    {
        $request->validate([
            'ids' => ['required_without:import_batch_uuid', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'import_batch_uuid' => ['required_without:ids', 'string', 'uuid'],
        ]);

        $ids = [];

        if ($request->has('import_batch_uuid')) {
            $batch = ImportBatch::query()
                ->where('uuid', $request->input('import_batch_uuid'))
                ->first();

            if (! $batch instanceof ImportBatch) {
                return $this->successResponse(null, 'Import batch not found.');
            }

            $ids = $batch->rows()
                ->where('status', 'committed')
                ->whereNotNull('document_id')
                ->pluck('document_id')
                ->unique()
                ->values()
                ->all();
        } else {
            $ids = $request->input('ids', []);
        }

        if ($ids === []) {
            return $this->successResponse(['posted_count' => 0, 'failed_count' => 0, 'results' => []], 'No invoices to post.');
        }

        return $this->successResponse($this->service->bulkPost($ids), 'Bulk post completed.');
    }

    /**
     * Ekspor daftar faktur ke Excel — menghormati filter, mengabaikan paginasi.
     */
    public function exportExcel(Request $request, ExcelExportService $excel): StreamedResponse
    {
        $query = SalesInvoice::query()->with('customer')->orderBy('invoice_date', 'desc');

        // Terapkan filter yang sama dengan daftar.
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        return $excel->download(
            $query,
            ['Invoice #', 'Date', 'Customer', 'Status', 'Total', 'Balance Due'],
            fn (SalesInvoice $inv): array => [
                $inv->invoice_number,
                $inv->invoice_date?->format('Y-m-d') ?? '',
                $inv->customer?->name ?? '',
                $inv->status,
                (string) $inv->grand_total,
                (string) $inv->balance_due,
            ],
            'sales-invoices'
        );
    }
}
