<?php

namespace App\Modules\Sales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\DeliveryOrder;
use App\Modules\Sales\Models\ProformaInvoice;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Requests\PostSalesInvoiceRequest;
use App\Modules\Sales\Requests\SalesActionRequest;
use App\Modules\Sales\Requests\StoreSalesInvoiceRequest;
use App\Modules\Sales\Requests\UpdateSalesInvoiceRequest;
use App\Modules\Sales\Services\SalesInvoiceService;
use App\Shared\Api\ApiErrorCode;
use App\Shared\Api\ApiResponse;
use App\Shared\Api\ResolvesAdjacentRecords;
use App\Shared\Export\ExcelExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesInvoiceController extends Controller
{
    use ApiResponse;
    use ResolvesAdjacentRecords;

    public function __construct(private readonly SalesInvoiceService $service) {}

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
            return $this->successResponse($this->service->createFromDeliveryOrder(DeliveryOrder::query()->findOrFail((int) $data['source_id']), $data), 'Sales invoice created from delivery order successfully', 201);
        }

        return $this->successResponse($this->service->create($data), 'Sales invoice created successfully', 201);
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
        return $this->successResponse($this->service->createFromSalesOrder(SalesOrder::query()->findOrFail($salesOrderId), $request->all()), 'Sales invoice created from sales order successfully', 201);
    }

    public function createFromDeliveryOrder(Request $request, int $deliveryOrderId): JsonResponse
    {
        return $this->successResponse($this->service->createFromDeliveryOrder(DeliveryOrder::query()->findOrFail($deliveryOrderId), $request->all()), 'Sales invoice created from delivery order successfully', 201);
    }

    public function createFromProforma(Request $request, int $proformaId): JsonResponse
    {
        return $this->successResponse($this->service->createFromProforma(ProformaInvoice::query()->findOrFail($proformaId), $request->all()), 'Sales invoice created from proforma successfully', 201);
    }

    public function approve(int $id): JsonResponse
    {
        return $this->successResponse($this->service->approve(SalesInvoice::query()->findOrFail($id)), 'Sales invoice approved successfully');
    }

    public function post(PostSalesInvoiceRequest $request, int $id): JsonResponse
    {
        $amount = $request->validated('applied_down_payment_amount');

        return $this->successResponse(
            $this->service->post(SalesInvoice::query()->findOrFail($id), $amount !== null ? (float) $amount : null),
            'Sales invoice posted successfully'
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
            $batch = \App\Modules\Imports\Models\ImportBatch::query()
                ->where('uuid', $request->input('import_batch_uuid'))
                ->first();

            if (! $batch instanceof \App\Modules\Imports\Models\ImportBatch) {
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
