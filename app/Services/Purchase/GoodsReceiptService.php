<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Inventory\InventoryPurchaseIntegrationService;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Services\Purchase\Concerns\HandlesPurchaseDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Validation\BusinessReferenceValidator;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class GoodsReceiptService
{
    use HandlesPurchaseDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly InventoryPurchaseIntegrationService $inventoryIntegration,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = GoodsReceipt::query()->with('vendor', 'purchaseOrder');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        if (! empty($filters['vendor_id'])) $query->where('vendor_id', (int) $filters['vendor_id']);
        if (! empty($filters['purchase_order_id'])) $query->where('purchase_order_id', (int) $filters['purchase_order_id']);
        return $query->orderByDesc('receipt_date')->orderByDesc('id')->get();
    }

    public function find(int $id): GoodsReceipt
    {
        return GoodsReceipt::query()->with('lines.product', 'lines.unit', 'vendor', 'purchaseOrder')->findOrFail($id);
    }

    public function create(array $data): GoodsReceipt
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureVendorExists((int) $data['vendor_id']);

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $lines = $this->normalizeReceiptLines((array) $data['lines']);
            $receipt = GoodsReceipt::query()->create(array_merge($this->guardedPurchaseHeader($data), [
                'receipt_number' => $this->documentNumberService->generate($company, DocumentType::GOODS_RECEIPT, (string) $data['receipt_date']),
                'status' => 'draft',
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));
            $receipt->lines()->createMany($lines);
            $receipt = $receipt->refresh()->load('lines', 'vendor', 'purchaseOrder');
            $this->auditPurchase($this->auditLogService, 'goods_receipt.created', $receipt, 'receipt_number');
            return $receipt;
        });
    }

    public function update(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt
    {
        if ($goodsReceipt->status !== 'draft') throw ApiException::make('GOODS_RECEIPT_NOT_EDITABLE', 'Goods receipt status is not editable.', 422);
        return DB::connection('tenant')->transaction(function () use ($goodsReceipt, $data) {
            $lines = $this->normalizeReceiptLines((array) ($data['lines'] ?? $goodsReceipt->lines()->get()->toArray()));
            $goodsReceipt->fill(array_merge($this->guardedPurchaseHeader($data), [
                'updated_by' => auth()->id(),
                'revision_no' => (int) $goodsReceipt->revision_no + 1,
            ]))->save();
            $goodsReceipt->lines()->delete();
            $goodsReceipt->lines()->createMany($lines);
            $goodsReceipt = $goodsReceipt->refresh()->load('lines', 'vendor', 'purchaseOrder');
            $this->auditPurchase($this->auditLogService, 'goods_receipt.updated', $goodsReceipt, 'receipt_number');
            return $goodsReceipt;
        });
    }

    public function createFromPurchaseOrder(PurchaseOrder $purchaseOrder, array $overrides = []): GoodsReceipt
    {
        if (in_array($purchaseOrder->status, ['cancelled', 'void', 'closed'], true)) throw ApiException::make('SOURCE_NOT_CONVERTIBLE', 'Purchase order is not available for conversion.', 422);
        $purchaseOrder->loadMissing('lines');
        $lines = $overrides['lines'] ?? $purchaseOrder->lines->map(function ($line): array {
            $remaining = max(0, (float) $line->quantity - (float) $line->received_quantity);
            return [
                'purchase_order_line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_code' => $line->product_code,
                'description' => $line->description,
                'quantity' => $remaining,
                'unit_id' => $line->unit_id,
                'warehouse_id' => $line->warehouse_id,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'expense_account_id' => $line->expense_account_id,
                'source_line_type' => 'purchase_order_line',
                'source_line_id' => $line->id,
            ];
        })->filter(fn (array $line): bool => (float) $line['quantity'] > 0)->values()->toArray();

        if ($lines === []) throw ApiException::make('PURCHASE_ORDER_ALREADY_RECEIVED', 'Purchase order has no remaining quantity to receive.', 422);

        return $this->create(array_merge([
            'receipt_date' => now()->toDateString(),
            'vendor_id' => $purchaseOrder->vendor_id,
            'purchase_order_id' => $purchaseOrder->id,
            'purchase_order_number' => $purchaseOrder->order_number,
            'source_type' => 'purchase_order',
            'source_id' => $purchaseOrder->id,
            'source_number' => $purchaseOrder->order_number,
            'source_revision' => $purchaseOrder->revision_no,
            'lines' => $lines,
        ], $overrides, ['lines' => $lines]));
    }

    public function receive(GoodsReceipt $goodsReceipt): GoodsReceipt
    {
        if ($goodsReceipt->status !== 'draft') throw ApiException::make('INVALID_GOODS_RECEIPT_STATUS', 'Invalid goods receipt status transition.', 422);

        $check = $this->dateGuardService->check((string) $goodsReceipt->receipt_date, 'post', 'purchase');
        if ($check->denied()) { $arr = $check->toArray(); throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']); }

        $goodsReceipt->loadMissing('lines.product');
        $hasStockLine = $goodsReceipt->lines->contains(
            fn ($l) => $l->warehouse_id && $l->product_id && (bool) optional($l->product)->is_stock_item
        );
        if ($hasStockLine) {
            $this->assertMapping('purchase.inventory_interim');
        }

        return DB::connection('tenant')->transaction(function () use ($goodsReceipt) {
            $goodsReceipt->load('lines');
            $this->validateRemainingQuantities($goodsReceipt);
            $this->validateStockWarehousesForPurchaseLines($goodsReceipt->lines->toArray());

            foreach ($goodsReceipt->lines as $line) {
                if (! $line->purchase_order_line_id) continue;
                $orderLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);
                $orderLine->received_quantity = (float) $orderLine->received_quantity + (float) $line->quantity;
                $orderLine->save();
            }

            $goodsReceipt->status = 'received';
            $goodsReceipt->received_by = auth()->id();
            $goodsReceipt->received_at = now();
            $goodsReceipt->save();

            $this->inventoryIntegration->createPurchaseInFromGoodsReceipt($goodsReceipt);

            if ($goodsReceipt->purchase_order_id) {
                $this->purchaseOrderService->refreshReceiptStatus(PurchaseOrder::query()->findOrFail($goodsReceipt->purchase_order_id));
            }

            $goodsReceipt = $goodsReceipt->refresh()->load('lines', 'vendor', 'purchaseOrder');
            $this->auditPurchase($this->auditLogService, 'goods_receipt.received', $goodsReceipt, 'receipt_number');
            return $goodsReceipt;
        });
    }

    public function cancel(GoodsReceipt $goodsReceipt, ?string $reason = null): GoodsReceipt
    {
        if ($goodsReceipt->status !== 'draft') throw ApiException::make('GOODS_RECEIPT_NOT_CANCELLABLE', 'Only draft goods receipt can be cancelled.', 422);
        $goodsReceipt->status = 'cancelled';
        $goodsReceipt->cancelled_by = auth()->id();
        $goodsReceipt->cancelled_at = now();
        $goodsReceipt->cancel_reason = $reason;
        $goodsReceipt->save();
        $goodsReceipt = $goodsReceipt->refresh()->load('lines', 'vendor', 'purchaseOrder');
        $this->auditPurchase($this->auditLogService, 'goods_receipt.cancelled', $goodsReceipt, 'receipt_number');
        return $goodsReceipt;
    }

    public function void(GoodsReceipt $goodsReceipt, ?string $reason = null): GoodsReceipt
    {
        if (! in_array($goodsReceipt->status, ['received'], true)) throw ApiException::make('GOODS_RECEIPT_NOT_VOIDABLE', 'Only received goods receipt can be voided.', 422);
        if ((float) $goodsReceipt->lines()->sum('billed_quantity') > 0) throw ApiException::make('GOODS_RECEIPT_HAS_BILLING', 'Billed goods receipt cannot be voided.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $check = $this->dateGuardService->check((string) $goodsReceipt->receipt_date, 'void', 'purchase');
        if ($check->denied()) { $arr = $check->toArray(); throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']); }

        return DB::connection('tenant')->transaction(function () use ($goodsReceipt, $reason) {
            $goodsReceipt->load('lines');
            $movementIds = $this->voidEffectService->voidStockMovementsForSource('goods_receipt', (int) $goodsReceipt->id, $reason);
            foreach ($goodsReceipt->lines as $line) {
                if (! $line->purchase_order_line_id) continue;
                $orderLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);
                $orderLine->received_quantity = max(0, (float) $orderLine->received_quantity - (float) $line->quantity);
                $orderLine->save();
            }

            $goodsReceipt->status = 'void';
            $goodsReceipt->voided_by = auth()->id();
            $goodsReceipt->voided_at = now();
            $goodsReceipt->void_reason = $reason;
            $goodsReceipt->save();

            if ($goodsReceipt->purchase_order_id) {
                $this->purchaseOrderService->refreshReceiptStatus(PurchaseOrder::query()->findOrFail($goodsReceipt->purchase_order_id));
            }

            $goodsReceipt = $goodsReceipt->refresh()->load('lines', 'vendor', 'purchaseOrder');
            $this->auditPurchase($this->auditLogService, 'goods_receipt.void', $goodsReceipt, 'receipt_number', ['reason' => $reason, 'reversed_stock_movement_ids' => $movementIds]);
            return $goodsReceipt;
        });
    }

    private function normalizeReceiptLines(array $lines): array
    {
        return array_values(array_map(function (array $line, int $index): array {
            $normalized = [
                'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                'product_id' => $line['product_id'] ?? null,
                'product_code' => $line['product_code'] ?? null,
                'description' => $line['description'] ?? '',
                'quantity' => (float) ($line['quantity'] ?? 0),
                'unit_id' => $line['unit_id'] ?? null,
                'warehouse_id' => $line['warehouse_id'] ?? null,
                'department_id' => $line['department_id'] ?? null,
                'project_id' => $line['project_id'] ?? null,
                'expense_account_id' => $line['expense_account_id'] ?? null,
                'source_line_type' => $line['source_line_type'] ?? null,
                'source_line_id' => $line['source_line_id'] ?? null,
                'sort_order' => $line['sort_order'] ?? $index,
                'metadata' => $line['metadata'] ?? null,
            ];
            app(BusinessReferenceValidator::class)->transactionalLine($normalized);

            return $normalized;
        }, $lines, array_keys($lines)));
    }

    private function validateRemainingQuantities(GoodsReceipt $goodsReceipt): void
    {
        foreach ($goodsReceipt->lines as $line) {
            if (! $line->purchase_order_line_id) continue;
            $orderLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);
            $remaining = (float) $orderLine->quantity - (float) $orderLine->received_quantity;
            if ((float) $line->quantity > $remaining) {
                throw ApiException::make('GOODS_RECEIPT_QUANTITY_EXCEEDS_REMAINING', 'Received quantity exceeds remaining purchase order quantity.', 422);
            }
        }
    }

    private function assertMapping(string $key): void
    {
        $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first();
        if (! $mapping?->account_id) {
            throw ApiException::make(
                'MAPPING_REQUIRED',
                "Account mapping [{$key}] is required for this operation.",
                422,
                ['account_mapping' => ["{$key} is not configured"]]
            );
        }
    }
}
