<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\PurchaseOrderLine;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorBillLine;
use App\Models\Tenant\VendorDeposit;
use App\Models\Tenant\VendorDepositAllocation;
use App\Models\Tenant\VendorPayment;
use App\Models\Tenant\PurchaseReturn;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Inventory\InventoryPurchaseIntegrationService;
use App\Services\Purchase\Concerns\HandlesPurchaseDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\PaymentTermDueDateService;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Services\Validation\BusinessReferenceValidator;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class VendorBillService
{
    use HandlesPurchaseDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly PurchaseCalculationService $calculationService,
        private readonly PaymentTermDueDateService $paymentTermDueDateService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly VendorDepositService $depositService,
        private readonly InventoryPurchaseIntegrationService $inventoryIntegration,
        private readonly PurchaseAccountResolverService $accountResolver,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = VendorBill::query()->with('vendor', 'paymentTerm');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        if (! empty($filters['vendor_id'])) $query->where('vendor_id', (int) $filters['vendor_id']);
        return $query->orderByDesc('bill_date')->orderByDesc('id')->get();
    }

    public function find(int $id): VendorBill
    {
        return $this->withAvailableDepositSummary(VendorBill::query()->with('lines.product', 'vendor', 'paymentTerm', 'purchaseOrder', 'goodsReceipt')->findOrFail($id));
    }

    public function create(array $data): VendorBill
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureVendorExists((int) $data['vendor_id']);
        app(BusinessReferenceValidator::class)->paymentTerm(isset($data['payment_term_id']) ? (int) $data['payment_term_id'] : null);
        $data = $this->paymentTermDueDateService->apply($data, 'bill_date', (int) $data['vendor_id']);

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $lines = $this->normalizePurchaseLines((array) $data['lines'], fn (array $line): array => [
                'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null,
            ]);
            $lines = $this->withDraftPurchaseExpenseSnapshots($lines);
            $totals = $this->calculationService->calculateDocument($lines, $data);
            $headerTotals = $totals; unset($headerTotals['lines']);
            $appliedDeposit = min((float) ($data['applied_vendor_deposit_amount'] ?? 0), (float) $headerTotals['grand_total']);

            $bill = VendorBill::query()->create(array_merge($this->guardedPurchaseHeader($data), $headerTotals, [
                'bill_number' => $this->documentNumberService->generate($company, DocumentType::VENDOR_BILL, (string) $data['bill_date']),
                'status' => 'draft',
                'applied_vendor_deposit_amount' => $appliedDeposit,
                'paid_amount' => 0,
                'balance_due' => (float) $headerTotals['grand_total'],
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));
            $bill->lines()->createMany($totals['lines']);
            $bill = $bill->refresh()->load('lines', 'vendor', 'paymentTerm');
            $this->auditPurchase($this->auditLogService, 'vendor_bill.created', $bill, 'bill_number');
            if ($this->shouldAutoPostOnCreateAccountingWorkflow()) {
                return $this->post($bill);
            }

            return $this->withAvailableDepositSummary($bill);
        });
    }

    public function update(VendorBill $bill, array $data): VendorBill
    {
        if ($bill->status !== 'draft') throw ApiException::make('VENDOR_BILL_NOT_EDITABLE', 'Vendor bill status is not editable.', 422);
        $data = $this->paymentTermDueDateService->apply(
            array_merge(['bill_date' => $bill->bill_date?->toDateString()], $data),
            'bill_date',
            (int) ($data['vendor_id'] ?? $bill->vendor_id)
        );
        app(BusinessReferenceValidator::class)->paymentTerm(isset($data['payment_term_id']) ? (int) $data['payment_term_id'] : null);

        return DB::connection('tenant')->transaction(function () use ($bill, $data) {
            $lines = $this->normalizePurchaseLines((array) ($data['lines'] ?? $bill->lines()->get()->toArray()), fn (array $line): array => [
                'purchase_order_line_id' => $line['purchase_order_line_id'] ?? null,
                'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null,
            ]);
            $lines = $this->withDraftPurchaseExpenseSnapshots($lines);
            $totals = $this->calculationService->calculateDocument($lines, array_merge($bill->toArray(), $data));
            $headerTotals = $totals; unset($headerTotals['lines']);
            $appliedDeposit = min((float) ($data['applied_vendor_deposit_amount'] ?? $bill->applied_vendor_deposit_amount), (float) $headerTotals['grand_total']);
            $bill->fill(array_merge($this->guardedPurchaseHeader($data), $headerTotals, [
                'applied_vendor_deposit_amount' => $appliedDeposit,
                'balance_due' => (float) $headerTotals['grand_total'],
                'updated_by' => auth()->id(),
                'revision_no' => (int) $bill->revision_no + 1,
            ]))->save();
            $bill->lines()->delete();
            $bill->lines()->createMany($totals['lines']);
            $bill = $bill->refresh()->load('lines', 'vendor', 'paymentTerm');
            $this->auditPurchase($this->auditLogService, 'vendor_bill.updated', $bill, 'bill_number');
            return $this->withAvailableDepositSummary($bill);
        });
    }

    public function createFromPurchaseOrder(PurchaseOrder $order, array $overrides = []): VendorBill
    {
        $this->guardConvertibleSource($order->status, 'purchase order');
        $order->loadMissing('lines');
        $lines = $this->purchaseOrderBillLines($order, (array) ($overrides['lines'] ?? []));
        $data = array_merge([
            'bill_date' => now()->toDateString(),
            'vendor_id' => $order->vendor_id,
            'purchase_order_id' => $order->id,
            'buyer_id' => $order->buyer_id,
            'currency_code' => $order->currency_code,
            'exchange_rate' => $order->exchange_rate,
            'is_taxable' => $order->is_taxable,
            'tax_included' => $order->tax_included,
            'header_discount_type' => $order->header_discount_type,
            'header_discount_value' => $order->header_discount_value,
            'source_type' => 'purchase_order',
            'source_id' => $order->id,
            'source_number' => $order->order_number,
            'source_revision' => $order->revision_no,
            'lines' => $lines,
        ], $overrides, ['lines' => $lines]);

        if (! array_key_exists('applied_vendor_deposit_amount', $data)) {
            $data['applied_vendor_deposit_amount'] = min($this->depositService->calculateAvailableForPurchaseOrder($order), $this->previewGrandTotal($data));
        }

        return $this->create($data);
    }

    public function createFromGoodsReceipt(GoodsReceipt $goodsReceipt, array $overrides = []): VendorBill
    {
        if (! in_array($goodsReceipt->status, ['received', 'partially_billed'], true)) {
            throw ApiException::make('GOODS_RECEIPT_NOT_RECEIVED', 'Only received goods receipts can be billed.', 422);
        }
        $goodsReceipt->loadMissing('lines');
        $lines = $this->goodsReceiptBillLines($goodsReceipt, (array) ($overrides['lines'] ?? []));
        return $this->create(array_merge([
            'bill_date' => now()->toDateString(),
            'vendor_id' => $goodsReceipt->vendor_id,
            'purchase_order_id' => $goodsReceipt->purchase_order_id,
            'goods_receipt_id' => $goodsReceipt->id,
            'source_type' => 'goods_receipt',
            'source_id' => $goodsReceipt->id,
            'source_number' => $goodsReceipt->receipt_number,
            'source_revision' => $goodsReceipt->revision_no,
            'lines' => $lines,
        ], $overrides, ['lines' => $lines]));
    }

    public function approve(VendorBill $bill): VendorBill
    {
        if ($bill->status !== 'draft') throw ApiException::make('INVALID_VENDOR_BILL_STATUS', 'Invalid vendor bill status transition.', 422);
        $bill->status = 'approved'; $bill->approved_by = auth()->id(); $bill->approved_at = now(); $bill->save();
        return $bill->refresh()->load('lines', 'vendor');
    }

    public function post(VendorBill $bill, ?float $appliedVendorDepositAmount = null): VendorBill
    {
        if (! in_array($bill->status, ['draft', 'approved'], true)) throw ApiException::make('INVALID_VENDOR_BILL_STATUS', 'Vendor bill cannot be posted from current status.', 422);
        $this->guardDate((string) $bill->bill_date);

        return DB::connection('tenant')->transaction(function () use ($bill, $appliedVendorDepositAmount) {
            $bill->load('lines.product', 'vendor');
            $this->validateSourceRemainingQuantities($bill);
            if (! $bill->goods_receipt_id) {
                $this->validateStockWarehousesForPurchaseLines($bill->lines->toArray());
            }
            $requestedDepositAmount = min((float) ($appliedVendorDepositAmount ?? $bill->applied_vendor_deposit_amount), (float) $bill->grand_total);
            $journal = $this->createBillJournal($bill);
            $bill->journal_entry_id = $journal->id;
            $bill->applied_vendor_deposit_amount = 0;
            $bill->paid_amount = 0;
            $bill->balance_due = (float) $bill->grand_total;
            $bill->status = 'posted';
            $bill->posted_by = auth()->id();
            $bill->posted_at = now();
            $bill->save();
            $this->updateSourceProgress($bill);

            $this->inventoryIntegration->createPurchaseInFromVendorBill($bill);

            if ($requestedDepositAmount > 0) {
                $bill->applied_vendor_deposit_amount = $requestedDepositAmount;
                $this->applyAvailableVendorDeposit($bill);
            }

            $bill = $this->withAvailableDepositSummary($bill->refresh()->load('lines', 'vendor'));
            $this->auditPurchase($this->auditLogService, 'vendor_bill.posted', $bill, 'bill_number');
            return $bill;
        });
    }

    public function void(VendorBill $bill, ?string $reason = null): VendorBill
    {
        if ($bill->status === 'void') throw ApiException::make('VENDOR_BILL_ALREADY_VOID', 'Vendor bill already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $bill->bill_date, 'void');
        if (VendorPayment::query()->where('vendor_bill_id', $bill->id)->where('status', 'posted')->exists()) {
            throw ApiException::make('VENDOR_BILL_HAS_PAYMENT', 'Void posted vendor payments before voiding this bill.', 422);
        }
        if (PurchaseReturn::query()->where('vendor_bill_id', $bill->id)->where('status', 'posted')->exists()) {
            throw ApiException::make('VENDOR_BILL_HAS_RETURN', 'Void posted purchase returns before voiding this bill.', 422);
        }
        return DB::connection('tenant')->transaction(function () use ($bill, $reason) {
            $bill->load('lines');
            $journalIds = $this->voidEffectService->voidJournalsForSource('vendor_bill', (int) $bill->id, $reason);
            $movementIds = $this->voidEffectService->voidStockMovementsForSource('vendor_bill', (int) $bill->id, $reason);
            $allocations = VendorDepositAllocation::query()->where('vendor_bill_id', $bill->id)->where('status', 'posted')->get();
            foreach ($allocations as $allocation) {
                $deposit = VendorDeposit::query()->lockForUpdate()->find($allocation->vendor_deposit_id);
                if ($deposit) {
                    $deposit->allocated_amount = max(0, (float) $deposit->allocated_amount - (float) $allocation->allocated_amount);
                    $deposit->remaining_amount = (float) $deposit->remaining_amount + (float) $allocation->allocated_amount;
                    $deposit->status = 'posted';
                    $deposit->save();
                }
                $this->voidEffectService->voidJournalById((int) $allocation->journal_entry_id, $reason);
                $allocation->status = 'void'; $allocation->voided_by = auth()->id(); $allocation->voided_at = now(); $allocation->void_reason = $reason; $allocation->save();
            }
            foreach ($bill->lines as $line) {
                if ($line->purchase_order_line_id && ($orderLine = PurchaseOrderLine::query()->lockForUpdate()->find($line->purchase_order_line_id))) {
                    $orderLine->billed_quantity = max(0, (float) $orderLine->billed_quantity - (float) $line->quantity);
                    $orderLine->save();
                }
                if ($line->goods_receipt_line_id && ($receiptLine = GoodsReceiptLine::query()->lockForUpdate()->find($line->goods_receipt_line_id))) {
                    $receiptLine->billed_quantity = max(0, (float) $receiptLine->billed_quantity - (float) $line->quantity);
                    $receiptLine->save();
                }
            }
            $this->refreshBillingSourceStatuses($bill);
            $bill->status = 'void'; $bill->voided_by = auth()->id(); $bill->voided_at = now(); $bill->void_reason = $reason; $bill->save();
            $this->auditPurchase($this->auditLogService, 'vendor_bill.voided', $bill, 'bill_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds, 'reversed_stock_movement_ids' => $movementIds, 'voided_allocation_ids' => $allocations->pluck('id')->all()]);
            return $bill->refresh()->load('lines', 'vendor');
        });
    }

    public function applyAvailableVendorDeposit(VendorBill $bill): VendorBill
    {
        if (! $bill->purchase_order_id || (float) $bill->applied_vendor_deposit_amount <= 0) return $bill;
        $remaining = (float) $bill->applied_vendor_deposit_amount;
        $deposits = VendorDeposit::query()->where('purchase_order_id', $bill->purchase_order_id)->whereIn('status', ['posted', 'partially_allocated'])->where('remaining_amount', '>', 0)->orderBy('deposit_date')->get();
        foreach ($deposits as $deposit) {
            if ($remaining <= 0) break;
            $amount = min($remaining, (float) $deposit->remaining_amount, (float) $bill->balance_due);
            $this->depositService->allocateToBill($deposit, $bill->refresh(), $amount);
            $remaining -= $amount;
        }
        if ($remaining > 0.0001) throw ApiException::make('VENDOR_DEPOSIT_INSUFFICIENT', 'Available vendor deposit is insufficient.', 422);
        return $bill->refresh();
    }

    private function createBillJournal(VendorBill $bill): JournalEntry
    {
        $journal = $this->createJournal($bill, 'Vendor bill '.$bill->bill_number);
        $lines = $this->billDebitJournalLines($bill);
        if ((float) $bill->tax_total > 0) {
            $lines[] = ['account_id' => $this->requiredMapping('purchase.tax_input'), 'description' => 'Input Tax', 'debit' => $bill->tax_total, 'credit' => 0, 'line_order' => count($lines) + 1];
        }
        $lines[] = ['account_id' => $this->accountResolver->resolveBillPayableAccountId($bill), 'description' => 'Accounts Payable', 'debit' => 0, 'credit' => $bill->grand_total, 'line_order' => count($lines) + 1];
        $journal->lines()->createMany($lines);
        return $journal->refresh();
    }

    private function createJournal(VendorBill $bill, string $description): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        return JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $bill->bill_date),
            'journal_date' => $bill->bill_date,
            'description' => $description,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => 'vendor_bill',
            'source_id' => $bill->id,
            'source_number' => $bill->bill_number,
            'source_revision' => $bill->revision_no,
            'source_module' => 'purchase',
            'is_system_generated' => true,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);
    }

    private function updateSourceProgress(VendorBill $bill): void
    {
        foreach ($bill->lines as $line) {
            if ($line->purchase_order_line_id && ($orderLine = PurchaseOrderLine::query()->find($line->purchase_order_line_id))) {
                $orderLine->billed_quantity = (float) $orderLine->billed_quantity + (float) $line->quantity;
                $orderLine->save();
            }
            if ($line->goods_receipt_line_id && ($receiptLine = GoodsReceiptLine::query()->find($line->goods_receipt_line_id))) {
                $receiptLine->billed_quantity = (float) $receiptLine->billed_quantity + (float) $line->quantity;
                $receiptLine->save();
            }
        }
        $this->refreshBillingSourceStatuses($bill);
    }

    private function requiredMapping(string $key): int
    {
        return app(BusinessReferenceValidator::class)->accountMapping($key, $key === 'purchase.tax_input' ? ['asset'] : null);
    }

    private function withDraftPurchaseExpenseSnapshots(array $lines): array
    {
        return array_map(function (array $line): array {
            if (! $this->lineReceivesStock($line)) {
                $line['expense_account_id'] = $this->accountResolver->tryPurchaseExpenseAccountIdForLine($line);
            }

            return $line;
        }, $lines);
    }

    private function billDebitJournalLines(VendorBill $bill): array
    {
        $bill->loadMissing('lines.product');
        $sourceTotal = max(0.0, (float) $bill->lines->sum('subtotal_after_discount'));
        $targetTotal = (float) $bill->subtotal_after_discount;
        $grouped = [];
        $descriptions = [];

        foreach ($bill->lines as $line) {
            $amount = (float) $line->subtotal_after_discount;
            if ($amount <= 0) {
                continue;
            }

            if ($this->lineReceivesStock($line)) {
                if ($bill->goods_receipt_id || $line->goods_receipt_line_id) {
                    $accountId = $this->accountResolver->getInventoryInterimAccountId();
                    $description = 'Inventory Interim';
                } else {
                    $accountId = $this->accountResolver->getInventoryAccountIdForLine($line);
                    $description = 'Inventory';
                }
            } else {
                $accountId = $this->accountResolver->getPurchaseExpenseAccountIdForLine($line);
                $description = 'Purchase Expense';
                if ((int) $line->expense_account_id !== $accountId) {
                    $line->expense_account_id = $accountId;
                    $line->save();
                }
            }

            $grouped[$accountId] = ($grouped[$accountId] ?? 0.0) + $amount;
            $descriptions[$accountId] ??= $description;
        }

        $lines = [];
        $running = 0.0;
        $accountIds = array_keys($grouped);
        foreach ($accountIds as $index => $accountId) {
            $amount = $sourceTotal > 0 ? round($grouped[$accountId] * ($targetTotal / $sourceTotal), 2) : 0.0;
            if ($index === array_key_last($accountIds)) {
                $amount = round($targetTotal - $running, 2);
            }
            $running += $amount;
            if ($amount <= 0) {
                continue;
            }
            $lines[] = [
                'account_id' => (int) $accountId,
                'description' => $descriptions[$accountId] ?? 'Purchase',
                'debit' => $amount,
                'credit' => 0,
                'line_order' => count($lines) + 1,
            ];
        }

        return $lines;
    }

    private function lineReceivesStock(array|VendorBillLine $line): bool
    {
        $warehouseId = is_array($line) ? ($line['warehouse_id'] ?? null) : $line->warehouse_id;

        return ! empty($warehouseId) && $this->accountResolver->lineIsStockItem($line);
    }

    private function guardDate(string $date, string $action = 'post'): void
    {
        $check = $this->dateGuardService->check($date, $action, 'purchase');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    private function previewGrandTotal(array $data): float
    {
        $lines = $this->normalizePurchaseLines((array) $data['lines']);
        return (float) $this->calculationService->calculateDocument($lines, $data)['grand_total'];
    }

    private function purchaseOrderBillLines(PurchaseOrder $order, array $requestedLines): array
    {
        $requested = collect($requestedLines)->keyBy(fn (array $line) => (string) ($line['purchase_order_line_id'] ?? $line['source_line_id'] ?? ''));
        $lines = $order->lines->map(function ($line) use ($requested, $requestedLines): ?array {
            $remaining = max(0, (float) $line->quantity - (float) $line->billed_quantity);
            if ($remaining <= 0 || ($requestedLines !== [] && ! $requested->has((string) $line->id))) {
                return null;
            }
            $quantity = $requestedLines === [] ? $remaining : (float) ($requested->get((string) $line->id)['quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > $remaining) {
                throw ApiException::make('BILL_QUANTITY_EXCEEDS_REMAINING', 'Bill quantity exceeds remaining purchase order quantity.', 422);
            }

            return array_merge($line->only(['product_id','product_code','description','unit_id','unit_price','discount_type','discount_value','tax_id','tax_rate','warehouse_id','department_id','project_id','expense_account_id','sort_order','metadata']), [
                'quantity' => $quantity,
                'purchase_order_line_id' => $line->id,
                'source_line_type' => 'purchase_order_line',
                'source_line_id' => $line->id,
            ]);
        })->filter()->values()->toArray();

        if ($lines === []) {
            throw ApiException::make('PURCHASE_ORDER_ALREADY_BILLED', 'Purchase order has no remaining quantity to bill.', 422);
        }

        return $lines;
    }

    private function goodsReceiptBillLines(GoodsReceipt $goodsReceipt, array $requestedLines): array
    {
        $requested = collect($requestedLines)->keyBy(fn (array $line) => (string) ($line['goods_receipt_line_id'] ?? $line['source_line_id'] ?? ''));
        $lines = $goodsReceipt->lines->map(function ($line) use ($requested, $requestedLines): ?array {
            $remaining = max(0, (float) $line->quantity - (float) $line->billed_quantity);
            if ($remaining <= 0 || ($requestedLines !== [] && ! $requested->has((string) $line->id))) {
                return null;
            }
            $quantity = $requestedLines === [] ? $remaining : (float) ($requested->get((string) $line->id)['quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > $remaining) {
                throw ApiException::make('BILL_QUANTITY_EXCEEDS_REMAINING', 'Bill quantity exceeds remaining received quantity.', 422);
            }
            $orderLine = $line->purchase_order_line_id ? PurchaseOrderLine::query()->find($line->purchase_order_line_id) : null;
            if (! $orderLine) {
                throw ApiException::make('GOODS_RECEIPT_PRICING_SOURCE_MISSING', 'Unable to resolve goods receipt price from its purchase order source.', 422);
            }

            return [
                'purchase_order_line_id' => $line->purchase_order_line_id,
                'goods_receipt_line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_code' => $line->product_code,
                'description' => $line->description,
                'quantity' => $quantity,
                'unit_id' => $line->unit_id,
                'unit_price' => $orderLine->unit_price,
                'discount_type' => $orderLine->discount_type,
                'discount_value' => $orderLine->discount_value,
                'tax_id' => $orderLine->tax_id,
                'tax_rate' => $orderLine->tax_rate,
                'warehouse_id' => $line->warehouse_id,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'expense_account_id' => $line->expense_account_id ?? $orderLine->expense_account_id,
                'source_line_type' => 'goods_receipt_line',
                'source_line_id' => $line->id,
                'sort_order' => $line->sort_order,
            ];
        })->filter()->values()->toArray();

        if ($lines === []) {
            throw ApiException::make('GOODS_RECEIPT_ALREADY_BILLED', 'Goods receipt has no remaining quantity to bill.', 422);
        }

        return $lines;
    }

    private function validateSourceRemainingQuantities(VendorBill $bill): void
    {
        foreach ($bill->lines as $line) {
            if ($line->purchase_order_line_id) {
                $source = PurchaseOrderLine::query()->lockForUpdate()->findOrFail($line->purchase_order_line_id);
                if ((float) $line->quantity > (float) $source->quantity - (float) $source->billed_quantity) {
                    throw ApiException::make('BILL_QUANTITY_EXCEEDS_REMAINING', 'Bill quantity exceeds remaining purchase order quantity.', 422);
                }
            }
            if ($line->goods_receipt_line_id) {
                $source = GoodsReceiptLine::query()->lockForUpdate()->findOrFail($line->goods_receipt_line_id);
                if ((float) $line->quantity > (float) $source->quantity - (float) $source->billed_quantity) {
                    throw ApiException::make('BILL_QUANTITY_EXCEEDS_REMAINING', 'Bill quantity exceeds remaining received quantity.', 422);
                }
            }
        }
    }

    private function refreshBillingSourceStatuses(VendorBill $bill): void
    {
        if ($bill->purchase_order_id && ($order = PurchaseOrder::query()->with('lines')->find($bill->purchase_order_id))) {
            $total = (float) $order->lines->sum('quantity');
            $billed = (float) $order->lines->sum('billed_quantity');
            if ($billed > 0) {
                $order->status = $billed >= $total ? 'billed' : 'partially_billed';
                $order->billed_amount = $order->lines->sum(fn ($line) => min((float) $line->billed_quantity, (float) $line->quantity) * (float) $line->unit_price);
                $order->save();
            } elseif (in_array($order->status, ['billed', 'partially_billed'], true)) {
                $received = (float) $order->lines->sum('received_quantity');
                $order->status = $received > 0 ? ($received >= $total ? 'received' : 'partially_received') : 'confirmed';
                $order->billed_amount = 0;
                $order->save();
            }
        }
        if ($bill->goods_receipt_id && ($receipt = GoodsReceipt::query()->with('lines')->find($bill->goods_receipt_id))) {
            $total = (float) $receipt->lines->sum('quantity');
            $billed = (float) $receipt->lines->sum('billed_quantity');
            if ($billed > 0) {
                $receipt->status = $billed >= $total ? 'billed' : 'partially_billed';
                $receipt->save();
            } elseif (in_array($receipt->status, ['billed', 'partially_billed'], true)) {
                $receipt->status = 'received';
                $receipt->save();
            }
        }
    }

    private function guardConvertibleSource(string $status, string $source): void
    {
        if (in_array($status, ['cancelled', 'void', 'closed'], true)) {
            throw ApiException::make('SOURCE_NOT_CONVERTIBLE', ucfirst($source).' is not available for conversion.', 422);
        }
    }

    private function withAvailableDepositSummary(VendorBill $bill): VendorBill
    {
        if (! $bill->vendor_id) {
            return $bill;
        }

        $bill->setAttribute('available_deposit_summary', $this->depositService->availableForVendor((int) $bill->vendor_id, [
            'purchase_order_id' => $bill->purchase_order_id,
        ]));

        return $bill;
    }
}
