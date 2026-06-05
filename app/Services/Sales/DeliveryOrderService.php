<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesInvoice;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Sales\Concerns\HandlesSalesDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Inventory\InventorySalesIntegrationService;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DeliveryOrderService
{
    use HandlesSalesDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly SalesOrderService $salesOrderService,
        private readonly InventorySalesIntegrationService $inventoryIntegration,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}
    public function list(array $filters = []): Collection { $q = DeliveryOrder::query()->with('customer', 'salesOrder'); if (! empty($filters['status'])) $q->where('status', (string) $filters['status']); return $q->orderByDesc('delivery_date')->orderByDesc('id')->get(); }
    public function find(int $id): DeliveryOrder { return DeliveryOrder::query()->with('lines.product', 'customer', 'salesOrder')->findOrFail($id); }

    public function create(array $data): DeliveryOrder
    {
        $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureCustomerExists((int) $data['customer_id']); $this->validateRemainingQuantities((array) $data['lines']);
        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $deliveryOrder = DeliveryOrder::query()->create(array_merge($this->guardedForHeader($data), [
                'delivery_number' => $this->documentNumberService->generate($company, DocumentType::DELIVERY_ORDER, (string) $data['delivery_date']),
                'status' => 'draft', 'created_by' => auth()->id(), 'updated_by' => auth()->id(),
            ]));
            $deliveryOrder->lines()->createMany($this->normalizeDeliveryLines((array) $data['lines']));
            return $deliveryOrder->refresh()->load('lines', 'customer', 'salesOrder');
        });
    }

    public function update(DeliveryOrder $deliveryOrder, array $data): DeliveryOrder
    {
        if (! in_array($deliveryOrder->status, ['draft', 'ready'], true)) throw ApiException::make('DELIVERY_ORDER_NOT_EDITABLE', 'Delivery order status is not editable.', 422);
        $this->validateRemainingQuantities((array) ($data['lines'] ?? []), $deliveryOrder);
        return DB::connection('tenant')->transaction(function () use ($deliveryOrder, $data) {
            $deliveryOrder->fill(array_merge($this->guardedForHeader($data), ['updated_by' => auth()->id(), 'revision_no' => (int) $deliveryOrder->revision_no + 1]))->save();
            if (isset($data['lines'])) { $deliveryOrder->lines()->delete(); $deliveryOrder->lines()->createMany($this->normalizeDeliveryLines((array) $data['lines'])); }
            return $deliveryOrder->refresh()->load('lines', 'customer', 'salesOrder');
        });
    }

    public function createFromSalesOrder(SalesOrder $salesOrder, array $overrides = []): DeliveryOrder
    {
        $this->guardConvertibleSource($salesOrder->status, 'sales order');
        $salesOrder->loadMissing('lines');
        $requestedLines = (array) ($overrides['lines'] ?? []);
        $requested = collect($requestedLines)->keyBy(fn (array $line) => (string) ($line['sales_order_line_id'] ?? $line['source_line_id'] ?? ''));
        $lines = $salesOrder->lines->map(function ($line) use ($requested, $requestedLines): ?array {
            $remaining = max(0, (float) $line->quantity - (float) $line->delivered_quantity);
            if ($remaining <= 0 || ($requestedLines !== [] && ! $requested->has((string) $line->id))) return null;
            $quantity = $requestedLines === [] ? $remaining : (float) ($requested->get((string) $line->id)['quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > $remaining) throw ApiException::make('DELIVERY_QUANTITY_EXCEEDS_REMAINING', 'Delivery quantity exceeds remaining sales order quantity.', 422);
            return ['sales_order_line_id' => $line->id, 'product_id' => $line->product_id, 'product_code' => $line->product_code, 'description' => $line->description, 'quantity' => $quantity, 'unit_id' => $line->unit_id, 'warehouse_id' => $line->warehouse_id, 'department_id' => $line->department_id, 'project_id' => $line->project_id, 'source_line_type' => 'sales_order_line', 'source_line_id' => $line->id, 'sort_order' => $line->sort_order];
        })->filter()->values()->toArray();
        if ($lines === []) throw ApiException::make('SALES_ORDER_ALREADY_DELIVERED', 'Sales order has no remaining quantity to deliver.', 422);
        return $this->create(array_merge([
            'delivery_date' => now()->toDateString(), 'customer_id' => $salesOrder->customer_id, 'sales_order_id' => $salesOrder->id,
            'sales_order_number' => $salesOrder->order_number, 'shipping_address' => $salesOrder->shipping_address,
            'source_type' => 'sales_order', 'source_id' => $salesOrder->id, 'source_number' => $salesOrder->order_number, 'source_revision' => $salesOrder->revision_no,
            'lines' => $lines,
        ], $overrides, ['lines' => $lines]));
    }

    public function markReady(DeliveryOrder $deliveryOrder): DeliveryOrder { return $this->transition($deliveryOrder, 'ready', 'ready_by', 'ready_at', ['draft']); }
    public function ship(DeliveryOrder $deliveryOrder): DeliveryOrder { return $this->transition($deliveryOrder, 'shipped', 'shipped_by', 'shipped_at', ['draft', 'ready']); }
    public function deliver(DeliveryOrder $deliveryOrder): DeliveryOrder
    {
        if ($deliveryOrder->status === 'delivered') {
            $this->inventoryIntegration->createSalesOutFromDeliveryOrder($deliveryOrder);
            return $deliveryOrder->refresh()->load('lines', 'customer', 'salesOrder');
        }
        if (! in_array($deliveryOrder->status, ['draft', 'ready', 'shipped'], true)) {
            throw ApiException::make('INVALID_DELIVERY_ORDER_STATUS', 'Delivery order cannot be delivered from current status.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($deliveryOrder) { $this->validateRemainingQuantities($deliveryOrder->lines()->get()->toArray(), $deliveryOrder); $deliveryOrder->load('lines', 'salesOrder'); foreach ($deliveryOrder->lines as $line) { if ($line->sales_order_line_id) { $orderLine = SalesOrderLine::query()->findOrFail($line->sales_order_line_id); $orderLine->delivered_quantity = (float) $orderLine->delivered_quantity + (float) $line->quantity; $orderLine->save(); } } $deliveryOrder->status = 'delivered'; $deliveryOrder->delivered_by = auth()->id(); $deliveryOrder->delivered_at = now(); $deliveryOrder->save(); $this->inventoryIntegration->createSalesOutFromDeliveryOrder($deliveryOrder); if ($deliveryOrder->salesOrder) $this->salesOrderService->refreshDeliveryStatus($deliveryOrder->salesOrder); return $deliveryOrder->refresh()->load('lines', 'customer', 'salesOrder'); });
    }
    public function cancel(DeliveryOrder $deliveryOrder, ?string $reason = null): DeliveryOrder { $deliveryOrder->cancel_reason = $reason; return $this->transition($deliveryOrder, 'cancelled', 'cancelled_by', 'cancelled_at', ['draft', 'ready', 'shipped']); }
    public function void(DeliveryOrder $deliveryOrder, ?string $reason = null): DeliveryOrder
    {
        if (! in_array($deliveryOrder->status, ['draft', 'ready', 'shipped', 'delivered'], true)) throw ApiException::make('INVALID_DELIVERY_ORDER_STATUS', 'Invalid delivery order status transition.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $check = $this->dateGuardService->check((string) $deliveryOrder->delivery_date, 'void', 'sales');
        if ($check->denied()) { $arr = $check->toArray(); throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']); }
        if (SalesInvoice::query()->where('delivery_order_id', $deliveryOrder->id)->where('status', '!=', 'void')->exists()) {
            throw ApiException::make('DELIVERY_ORDER_HAS_INVOICE', 'Void linked sales invoices before voiding this delivery order.', 422);
        }
        return DB::connection('tenant')->transaction(function () use ($deliveryOrder, $reason) {
            $deliveryOrder->load('lines', 'salesOrder');
            $movementIds = $this->voidEffectService->voidStockMovementsForSource('delivery_order', (int) $deliveryOrder->id, $reason);
            if ($deliveryOrder->status === 'delivered') {
                foreach ($deliveryOrder->lines as $line) {
                    if ($line->sales_order_line_id && ($orderLine = SalesOrderLine::query()->lockForUpdate()->find($line->sales_order_line_id))) {
                        $orderLine->delivered_quantity = max(0, (float) $orderLine->delivered_quantity - (float) $line->quantity);
                        $orderLine->save();
                    }
                }
                if ($deliveryOrder->salesOrder) $this->salesOrderService->refreshDeliveryStatus($deliveryOrder->salesOrder);
            }
            $deliveryOrder->status = 'void'; $deliveryOrder->voided_by = auth()->id(); $deliveryOrder->voided_at = now(); $deliveryOrder->void_reason = $reason; $deliveryOrder->save();
            $this->auditSales($this->auditLogService, 'delivery_order.voided', 'sales', $deliveryOrder, 'delivery_number', ['reason' => $reason, 'reversed_stock_movement_ids' => $movementIds]);
            return $deliveryOrder->refresh()->load('lines', 'customer', 'salesOrder');
        });
    }

    private function normalizeDeliveryLines(array $lines): array { return array_values(array_map(fn (array $line, int $i): array => ['sales_order_line_id' => $line['sales_order_line_id'] ?? null, 'product_id' => $line['product_id'] ?? null, 'product_code' => $line['product_code'] ?? null, 'description' => $line['description'], 'quantity' => (float) $line['quantity'], 'unit_id' => $line['unit_id'] ?? null, 'warehouse_id' => $line['warehouse_id'] ?? null, 'department_id' => $line['department_id'] ?? null, 'project_id' => $line['project_id'] ?? null, 'source_line_type' => $line['source_line_type'] ?? null, 'source_line_id' => $line['source_line_id'] ?? null, 'sort_order' => $line['sort_order'] ?? $i, 'metadata' => $line['metadata'] ?? null], $lines, array_keys($lines))); }
    private function validateRemainingQuantities(array $lines, ?DeliveryOrder $current = null): void { foreach ($lines as $line) { if (empty($line['sales_order_line_id'])) continue; $orderLine = SalesOrderLine::query()->findOrFail((int) $line['sales_order_line_id']); $currentQuantity = $current ? (float) $current->lines()->where('sales_order_line_id', $orderLine->id)->sum('quantity') : 0.0; if ((float) $line['quantity'] > (float) $orderLine->quantity - (float) $orderLine->delivered_quantity + $currentQuantity) throw ApiException::make('DELIVERY_QUANTITY_EXCEEDS_REMAINING', 'Delivery quantity exceeds remaining sales order quantity.', 422); } }
    private function transition(DeliveryOrder $deliveryOrder, string $status, string $userField, string $dateField, array $from): DeliveryOrder { if (! in_array($deliveryOrder->status, $from, true)) throw ApiException::make('INVALID_DELIVERY_ORDER_STATUS', 'Invalid delivery order status transition.', 422); $deliveryOrder->status = $status; $deliveryOrder->{$userField} = auth()->id(); $deliveryOrder->{$dateField} = now(); $deliveryOrder->save(); return $deliveryOrder->refresh()->load('lines', 'customer', 'salesOrder'); }
    private function guardConvertibleSource(string $status, string $source): void { if (in_array($status, ['cancelled', 'void', 'closed'], true)) throw ApiException::make('SOURCE_NOT_CONVERTIBLE', ucfirst($source).' is not available for conversion.', 422); }
}
