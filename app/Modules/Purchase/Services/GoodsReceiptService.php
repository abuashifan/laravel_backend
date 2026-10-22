<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Inventory\Services\InventoryPurchaseIntegrationService;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\Purchase\Models\GoodsReceipt;
use App\Modules\Purchase\Models\PurchaseOrder;
use App\Modules\Purchase\Models\PurchaseOrderLine;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Services\Concerns\HandlesPurchaseDocuments;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Audit\AuditLogService;
use App\Shared\DocumentNumbering\DocumentNumberService;
use App\Shared\DocumentNumbering\DocumentType;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\ChangesTransactionDocuments;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
use App\Shared\TransactionLifecycle\TransactionDependents;
use App\Shared\TransactionLifecycle\TransactionModule;
use App\Shared\TransactionLifecycle\TransactionPolicyService;
use App\Shared\TransactionLifecycle\TransactionVoidEffectService;
use App\Shared\Validation\BusinessReferenceValidator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class GoodsReceiptService
{
    use AppliesListQuery;
    use ChangesTransactionDocuments;
    use HandlesPurchaseDocuments;

    protected array $listSearchable = ['receipt_number'];

    protected array $listSearchableRelations = ['vendor' => ['name', 'contact_code']];

    protected string $listDateColumn = 'receipt_date';

    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['receipt_date' => 'desc', 'id' => 'desc'];

    protected array $listSortable = ['receipt_number', 'receipt_date', 'status', 'created_at'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly InventoryPurchaseIntegrationService $inventoryIntegration,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,GoodsReceipt>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = GoodsReceipt::query()->with('vendor', 'purchaseOrder', 'warehouse');

        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }
        if (! empty($filters['purchase_order_id'])) {
            $query->where('purchase_order_id', (int) $filters['purchase_order_id']);
        }

        return $this->applyListQuery($query, $filters);
    }

    public function find(int $id): GoodsReceipt
    {
        return GoodsReceipt::query()->with('lines.product', 'lines.unit', 'vendor', 'purchaseOrder', 'warehouse')->findOrFail($id);
    }

    public function create(array $data): GoodsReceipt
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }
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

    private function updateDraft(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt
    {

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
        if (in_array($purchaseOrder->status, ['cancelled', 'void', 'closed'], true)) {
            throw ApiException::make('SOURCE_NOT_CONVERTIBLE', 'Purchase order is not available for conversion.', 422);
        }
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

        if ($lines === []) {
            throw ApiException::make('PURCHASE_ORDER_ALREADY_RECEIVED', 'Purchase order has no remaining quantity to receive.', 422);
        }

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

    /**
     * Edit penerimaan barang menurut mode lock/open. Draft diedit langsung;
     * yang sudah diterima (mode open) dibalik efeknya -- mutasi stok di-void,
     * jumlah diterima di PO dikembalikan -- lalu diedit dan diterima ulang.
     */
    public function update(GoodsReceipt $goodsReceipt, array $data): GoodsReceipt
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_EDIT,
            TransactionModule::PURCHASE,
            (string) $goodsReceipt->status,
            $this->dependents($goodsReceipt),
            $goodsReceipt->receipt_date?->toDateString(),
        );

        if ($goodsReceipt->status === 'cancelled') {
            throw ApiException::make('GOODS_RECEIPT_NOT_EDITABLE', 'Penerimaan barang yang dibatalkan tidak bisa diedit.', 422);
        }

        if ($goodsReceipt->status === 'draft') {
            return $this->updateDraft($goodsReceipt, $data);
        }

        return DB::connection('tenant')->transaction(function () use ($goodsReceipt, $data) {
            $this->guardGoodsReceiptVoidDate($goodsReceipt);
            $this->reverseReceiveEffects($goodsReceipt, 'Diedit setelah diterima');
            $goodsReceipt->status = 'draft';
            $goodsReceipt->received_by = null;
            $goodsReceipt->received_at = null;
            $goodsReceipt->save();
            $this->refreshOrderReceiptStatus($goodsReceipt);
            $this->auditPurchase($this->auditLogService, 'goods_receipt.reopened', $goodsReceipt, 'receipt_number');

            return $this->receive($this->updateDraft($goodsReceipt->refresh(), $data));
        });
    }

    /**
     * Hapus penerimaan barang. Draft/dibatalkan dihapus permanen; yang sudah
     * diterima di-void lalu ditandai terhapus.
     */
    public function delete(GoodsReceipt $goodsReceipt, ?string $reason = null): void
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_DELETE,
            TransactionModule::PURCHASE,
            (string) $goodsReceipt->status,
            $this->dependents($goodsReceipt),
            $goodsReceipt->receipt_date?->toDateString(),
            ['draft', 'cancelled'],
        );

        $reason = trim((string) $reason);

        DB::connection('tenant')->transaction(function () use ($goodsReceipt, $reason): void {
            if (in_array($goodsReceipt->status, ['draft', 'cancelled'], true)) {
                $this->auditPurchase($this->auditLogService, 'goods_receipt.deleted', $goodsReceipt, 'receipt_number', ['reason' => $reason, 'permanent' => true]);
                $goodsReceipt->lines()->delete();
                $goodsReceipt->forceDelete();

                return;
            }

            $voided = $this->void($goodsReceipt, $this->deleteVoidReason($reason));
            $this->markVoidedDocumentDeleted($voided, $reason);
            $this->auditPurchase($this->auditLogService, 'goods_receipt.deleted', $voided, 'receipt_number', ['reason' => $reason, 'permanent' => false]);
        });
    }

    /**
     * Tagihan dan retur yang memakai penerimaan barang ini.
     *
     * @return list<string>
     */
    public function dependents(GoodsReceipt $goodsReceipt): array
    {
        $deps = app(TransactionDependents::class);
        $billed = (float) $goodsReceipt->lines()->sum('billed_quantity') > 0
            ? $deps->documents('tagihan vendor', VendorBill::query()->where('goods_receipt_id', $goodsReceipt->id)->where('status', '!=', 'void')->pluck('bill_number'))
            : [];

        return array_merge(
            $billed !== [] || (float) $goodsReceipt->lines()->sum('billed_quantity') <= 0 ? $billed : ['tagihan vendor'],
            $deps->documents('retur pembelian', PurchaseReturn::query()->where('goods_receipt_id', $goodsReceipt->id)->where('status', '!=', 'void')->pluck('return_number')),
        );
    }

    /**
     * Efek penerimaan: mutasi stok masuk dan jumlah diterima di PO. Dipakai
     * bersama void & edit-setelah-diterima.
     *
     * @return array<int>
     */
    private function reverseReceiveEffects(GoodsReceipt $goodsReceipt, string $reason): array
    {
        $goodsReceipt->load('lines');
        $movementIds = $this->voidEffectService->voidStockMovementsForSource('goods_receipt', (int) $goodsReceipt->id, $reason);
        foreach ($goodsReceipt->lines as $line) {
            if (! $line->purchase_order_line_id) {
                continue;
            }
            $orderLine = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);
            $orderLine->received_quantity = max(0, (float) $orderLine->received_quantity - (float) $line->quantity);
            $orderLine->save();
        }

        return $movementIds;
    }

    private function refreshOrderReceiptStatus(GoodsReceipt $goodsReceipt): void
    {
        if ($goodsReceipt->purchase_order_id) {
            $this->purchaseOrderService->refreshReceiptStatus(PurchaseOrder::query()->findOrFail($goodsReceipt->purchase_order_id));
        }
    }

    private function guardGoodsReceiptVoidDate(GoodsReceipt $goodsReceipt): void
    {
        $check = $this->dateGuardService->check((string) $goodsReceipt->receipt_date, 'void', 'purchase');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    public function receive(GoodsReceipt $goodsReceipt): GoodsReceipt
    {
        if ($goodsReceipt->status !== 'draft') {
            throw ApiException::make('INVALID_GOODS_RECEIPT_STATUS', 'Invalid goods receipt status transition.', 422);
        }

        $check = $this->dateGuardService->check((string) $goodsReceipt->receipt_date, 'post', 'purchase');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }

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
                if (! $line->purchase_order_line_id) {
                    continue;
                }
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
        if ($goodsReceipt->status !== 'draft') {
            throw ApiException::make('GOODS_RECEIPT_NOT_CANCELLABLE', 'Only draft goods receipt can be cancelled.', 422);
        }
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
        if (! in_array($goodsReceipt->status, ['received'], true)) {
            throw ApiException::make('GOODS_RECEIPT_NOT_VOIDABLE', 'Only received goods receipt can be voided.', 422);
        }
        if ((float) $goodsReceipt->lines()->sum('billed_quantity') > 0) {
            throw ApiException::make('GOODS_RECEIPT_HAS_BILLING', 'Billed goods receipt cannot be voided.', 422);
        }
        $reason = $this->voidEffectService->requireReason($reason);
        $check = $this->dateGuardService->check((string) $goodsReceipt->receipt_date, 'void', 'purchase');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }

        return DB::connection('tenant')->transaction(function () use ($goodsReceipt, $reason) {
            $movementIds = $this->reverseReceiveEffects($goodsReceipt, $reason);

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
            if (! $line->purchase_order_line_id) {
                continue;
            }
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
