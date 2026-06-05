<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseRequest;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Purchase\Concerns\HandlesPurchaseDocuments;
use App\Services\Tenant\TenantContext;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    use HandlesPurchaseDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly PurchaseCalculationService $calculationService,
        private readonly PurchaseRequestService $requestService,
        private readonly VendorDepositService $depositService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = PurchaseOrder::query()->with('vendor');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        if (! empty($filters['vendor_id'])) $query->where('vendor_id', (int) $filters['vendor_id']);
        return $query->orderByDesc('order_date')->orderByDesc('id')->get();
    }

    public function find(int $id): PurchaseOrder
    {
        return PurchaseOrder::query()->with('lines.product', 'vendor', 'purchaseRequest', 'deposits')->findOrFail($id);
    }

    public function create(array $data): PurchaseOrder
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureVendorExists((int) $data['vendor_id']);

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $lines = $this->normalizePurchaseLines((array) $data['lines'], fn (array $line): array => [
                'purchase_request_line_id' => $line['purchase_request_line_id'] ?? null,
            ]);
            $totals = $this->calculationService->calculateDocument($lines, $data);
            $headerTotals = $totals; unset($headerTotals['lines']);
            $order = PurchaseOrder::query()->create(array_merge($this->guardedPurchaseHeader($data), $headerTotals, [
                'order_number' => $this->documentNumberService->generate($company, DocumentType::PURCHASE_ORDER, (string) $data['order_date']),
                'status' => 'draft',
                'has_down_payment' => (bool) ($data['has_down_payment'] ?? false),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));
            $order->lines()->createMany($totals['lines']);
            if ((bool) ($data['has_down_payment'] ?? false) && ! empty($data['vendor_deposit'])) {
                $this->depositService->createFromPurchaseOrder($order->refresh(), (array) $data['vendor_deposit']);
            }
            $order = $order->refresh()->load('lines', 'vendor', 'deposits');
            $this->auditPurchase($this->auditLogService, 'purchase_order.created', $order, 'order_number');
            return $order;
        });
    }

    public function update(PurchaseOrder $order, array $data): PurchaseOrder
    {
        if (! in_array($order->status, ['draft', 'approved'], true)) throw ApiException::make('PURCHASE_ORDER_NOT_EDITABLE', 'Purchase order status is not editable.', 422);
        return DB::connection('tenant')->transaction(function () use ($order, $data) {
            $lines = $this->normalizePurchaseLines((array) ($data['lines'] ?? $order->lines()->get()->toArray()), fn (array $line): array => [
                'purchase_request_line_id' => $line['purchase_request_line_id'] ?? null,
            ]);
            $totals = $this->calculationService->calculateDocument($lines, array_merge($order->toArray(), $data));
            $headerTotals = $totals; unset($headerTotals['lines']);
            $order->fill(array_merge($this->guardedPurchaseHeader($data), $headerTotals, [
                'updated_by' => auth()->id(),
                'revision_no' => (int) $order->revision_no + 1,
            ]))->save();
            $order->lines()->delete();
            $order->lines()->createMany($totals['lines']);
            $order = $order->refresh()->load('lines', 'vendor', 'deposits');
            $this->auditPurchase($this->auditLogService, 'purchase_order.updated', $order, 'order_number');
            return $order;
        });
    }

    public function createFromPurchaseRequest(PurchaseRequest $request, array $overrides = []): PurchaseOrder
    {
        if (in_array($request->status, ['converted', 'cancelled', 'rejected'], true)) throw ApiException::make('PURCHASE_REQUEST_NOT_CONVERTIBLE', 'Purchase request is not available for conversion.', 422);
        if (empty($overrides['vendor_id'])) throw ApiException::make('VENDOR_REQUIRED', 'Vendor is required to convert purchase request.', 422);
        $request->loadMissing('lines');
        $order = $this->create(array_merge([
            'order_date' => now()->toDateString(),
            'vendor_id' => $overrides['vendor_id'],
            'purchase_request_id' => $request->id,
            'purchase_request_number' => $request->request_number,
            'currency_code' => 'IDR',
            'exchange_rate' => 1,
            'source_type' => 'purchase_request',
            'source_id' => $request->id,
            'source_number' => $request->request_number,
            'source_revision' => $request->revision_no,
            'lines' => $request->lines->map(fn ($line) => [
                'product_id' => $line->product_id,
                'product_code' => $line->product_code,
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_id' => $line->unit_id,
                'unit_price' => $line->estimated_unit_price,
                'warehouse_id' => $line->warehouse_id,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'purchase_request_line_id' => $line->id,
                'source_line_type' => 'purchase_request_line',
                'source_line_id' => $line->id,
            ])->toArray(),
        ], $overrides));
        $this->requestService->markConverted($request);
        return $order;
    }

    public function approve(PurchaseOrder $order): PurchaseOrder { return $this->transition($order, 'approved', 'approved_by', 'approved_at', ['draft']); }
    public function confirm(PurchaseOrder $order): PurchaseOrder { return $this->transition($order, 'confirmed', 'confirmed_by', 'confirmed_at', ['draft', 'approved']); }
    public function cancel(PurchaseOrder $order, ?string $reason = null): PurchaseOrder { $order->cancel_reason = $reason; return $this->transition($order, 'cancelled', 'cancelled_by', 'cancelled_at', ['draft', 'approved', 'confirmed']); }
    public function close(PurchaseOrder $order): PurchaseOrder { return $this->transition($order, 'closed', 'closed_by', 'closed_at', ['confirmed', 'received', 'billed']); }

    public function refreshReceiptStatus(PurchaseOrder $order): PurchaseOrder
    {
        $order->load('lines');
        $total = (float) $order->lines->sum('quantity');
        $received = (float) $order->lines->sum('received_quantity');

        if ($received > 0) {
            $order->status = $received >= $total ? 'received' : 'partially_received';
            $order->received_amount = $order->lines->sum(fn ($line) => min((float) $line->received_quantity, (float) $line->quantity) * (float) $line->unit_price);
            $order->save();
        } elseif (in_array($order->status, ['received', 'partially_received'], true)) {
            $order->status = 'confirmed';
            $order->received_amount = 0;
            $order->save();
        }

        return $order->refresh();
    }

    private function transition(PurchaseOrder $order, string $status, string $userField, string $dateField, array $from): PurchaseOrder
    {
        if (! in_array($order->status, $from, true)) throw ApiException::make('INVALID_PURCHASE_ORDER_STATUS', 'Invalid purchase order status transition.', 422);
        $order->status = $status; $order->{$userField} = auth()->id(); $order->{$dateField} = now(); $order->save();
        $order = $order->refresh()->load('lines', 'vendor', 'deposits');
        $this->auditPurchase($this->auditLogService, 'purchase_order.'.$status, $order, 'order_number');
        return $order;
    }
}
