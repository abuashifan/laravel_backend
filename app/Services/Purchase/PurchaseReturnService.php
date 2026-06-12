<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\GoodsReceiptLine;
use App\Models\Tenant\GoodsReceipt;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\Contact;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorBillLine;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Inventory\InventoryPurchaseIntegrationService;
use App\Services\Purchase\Concerns\HandlesPurchaseDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Services\Audit\AuditLogService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class PurchaseReturnService
{
    use HandlesPurchaseDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly InventoryPurchaseIntegrationService $inventoryIntegration,
        private readonly PurchaseAccountResolverService $accountResolver,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    public function list(array $filters = []): Collection { $q = PurchaseReturn::query()->with('vendor', 'vendorBill'); if (! empty($filters['status'])) $q->where('status', (string) $filters['status']); return $q->orderByDesc('return_date')->orderByDesc('id')->get(); }
    public function find(int $id): PurchaseReturn { return PurchaseReturn::query()->with('lines', 'vendor', 'vendorBill', 'goodsReceipt')->findOrFail($id); }

    public function create(array $data): PurchaseReturn
    {
        $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureVendorExists((int) $data['vendor_id']);
        $lines = $this->normalizeReturnLines((array) $data['lines']);
        $this->validateReturnedQuantities($lines);
        $totals = $this->totals($lines);
        return DB::connection('tenant')->transaction(function () use ($company, $data, $lines, $totals) {
            $return = PurchaseReturn::query()->create(array_merge($this->guardedPurchaseHeader($data), $totals, [
                'return_number' => $this->documentNumberService->generate($company, DocumentType::PURCHASE_RETURN, (string) $data['return_date']),
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]));
            $return->lines()->createMany($lines);
            $return = $return->refresh()->load('lines', 'vendor', 'vendorBill');
            return $this->shouldAutoPostOnCreateAccountingWorkflow() ? $this->post($return) : $return;
        });
    }

    public function update(PurchaseReturn $return, array $data): PurchaseReturn
    {
        if ($return->status !== 'draft') throw ApiException::make('PURCHASE_RETURN_NOT_EDITABLE', 'Purchase return is not editable.', 422);
        $lines = $this->normalizeReturnLines((array) ($data['lines'] ?? $return->lines()->get()->toArray()));
        $this->validateReturnedQuantities($lines, $return);
        return DB::connection('tenant')->transaction(function () use ($return, $data, $lines) {
            $return->fill(array_merge($this->guardedPurchaseHeader($data), $this->totals($lines), ['revision_no' => (int) $return->revision_no + 1]))->save();
            $return->lines()->delete();
            $return->lines()->createMany($lines);
            return $return->refresh()->load('lines', 'vendor', 'vendorBill');
        });
    }

    public function createFromVendorBill(VendorBill $bill, array $overrides = []): PurchaseReturn
    {
        $bill->loadMissing('lines');
        if ($bill->status === 'paid') throw ApiException::make('PAID_BILL_RETURN_BLOCKED', 'Paid bill return is deferred for vendor credit workflow.', 422);
        return $this->create(array_merge([
            'return_date' => now()->toDateString(), 'vendor_id' => $bill->vendor_id, 'vendor_bill_id' => $bill->id,
            'currency_code' => $bill->currency_code, 'exchange_rate' => $bill->exchange_rate,
            'source_type' => 'vendor_bill', 'source_id' => $bill->id, 'source_number' => $bill->bill_number, 'source_revision' => $bill->revision_no,
            'lines' => $bill->lines->map(fn ($line) => ['vendor_bill_line_id' => $line->id, 'product_id' => $line->product_id, 'product_code' => $line->product_code, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_id' => $line->unit_id, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount, 'tax_amount' => $line->tax_amount, 'line_total' => $line->line_total, 'warehouse_id' => $line->warehouse_id, 'department_id' => $line->department_id, 'project_id' => $line->project_id, 'expense_account_id' => $line->expense_account_id, 'source_line_type' => 'vendor_bill_line', 'source_line_id' => $line->id, 'sort_order' => $line->sort_order])->toArray(),
        ], $overrides));
    }

    public function createFromGoodsReceipt(GoodsReceipt $receipt, array $overrides = []): PurchaseReturn
    {
        $receipt->loadMissing('lines');
        return $this->create(array_merge([
            'return_date' => now()->toDateString(), 'vendor_id' => $receipt->vendor_id, 'goods_receipt_id' => $receipt->id,
            'source_type' => 'goods_receipt', 'source_id' => $receipt->id, 'source_number' => $receipt->receipt_number, 'source_revision' => $receipt->revision_no,
            'lines' => $receipt->lines->map(fn ($line) => ['goods_receipt_line_id' => $line->id, 'product_id' => $line->product_id, 'product_code' => $line->product_code, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_id' => $line->unit_id, 'unit_price' => 0, 'line_total' => 0, 'warehouse_id' => $line->warehouse_id, 'department_id' => $line->department_id, 'project_id' => $line->project_id, 'expense_account_id' => $line->expense_account_id, 'source_line_type' => 'goods_receipt_line', 'source_line_id' => $line->id, 'sort_order' => $line->sort_order])->toArray(),
        ], $overrides));
    }

    public function approve(PurchaseReturn $return): PurchaseReturn { if ($return->status !== 'draft') throw ApiException::make('INVALID_PURCHASE_RETURN_STATUS', 'Invalid purchase return status transition.', 422); $return->status = 'approved'; $return->approved_by = auth()->id(); $return->approved_at = now(); $return->save(); return $return->refresh()->load('lines'); }

    public function post(PurchaseReturn $return): PurchaseReturn
    {
        if (! in_array($return->status, ['draft', 'approved'], true)) throw ApiException::make('INVALID_PURCHASE_RETURN_STATUS', 'Purchase return cannot be posted.', 422);
        $this->guardDate((string) $return->return_date);
        $this->assertMapping('purchase.return');
        return DB::connection('tenant')->transaction(function () use ($return) {
            $return->load('lines'); $journal = $this->journal($return);
            $return->journal_entry_id = $journal->id; $return->status = 'posted'; $return->posted_by = auth()->id(); $return->posted_at = now(); $return->save();
            $this->inventoryIntegration->createPurchaseReturnOut($return);
            $this->updateBill($return);
            return $return->refresh()->load('lines', 'vendor', 'vendorBill');
        });
    }

    public function void(PurchaseReturn $return, ?string $reason = null): PurchaseReturn
    {
        if ($return->status === 'void') throw ApiException::make('PURCHASE_RETURN_ALREADY_VOID', 'Purchase return already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $return->return_date, 'void');
        return DB::connection('tenant')->transaction(function () use ($return, $reason) {
            $return->load('lines');
            $journalIds = $this->voidEffectService->voidJournalsForSource('purchase_return', (int) $return->id, $reason);
            $movementIds = $this->voidEffectService->voidStockMovementsForSource('purchase_return', (int) $return->id, $reason);
            if ($return->status === 'posted') $this->restoreSource($return);
            $return->status = 'void'; $return->voided_by = auth()->id(); $return->voided_at = now(); $return->void_reason = $reason; $return->save();
            $this->auditPurchase($this->auditLogService, 'purchase_return.voided', $return, 'return_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds, 'reversed_stock_movement_ids' => $movementIds]);
            return $return->refresh();
        });
    }

    private function normalizeReturnLines(array $lines): array { return array_values(array_map(fn (array $line, int $i): array => ['vendor_bill_line_id' => $line['vendor_bill_line_id'] ?? null, 'goods_receipt_line_id' => $line['goods_receipt_line_id'] ?? null, 'product_id' => $line['product_id'] ?? null, 'product_code' => $line['product_code'] ?? null, 'description' => $line['description'], 'quantity' => (float) $line['quantity'], 'unit_id' => $line['unit_id'] ?? null, 'unit_price' => (float) ($line['unit_price'] ?? 0), 'discount_amount' => (float) ($line['discount_amount'] ?? 0), 'tax_amount' => (float) ($line['tax_amount'] ?? 0), 'line_total' => (float) ($line['line_total'] ?? ((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0) - (float) ($line['discount_amount'] ?? 0) + (float) ($line['tax_amount'] ?? 0))), 'warehouse_id' => $line['warehouse_id'] ?? null, 'department_id' => $line['department_id'] ?? null, 'project_id' => $line['project_id'] ?? null, 'expense_account_id' => $line['expense_account_id'] ?? null, 'source_line_type' => $line['source_line_type'] ?? null, 'source_line_id' => $line['source_line_id'] ?? null, 'sort_order' => $line['sort_order'] ?? $i, 'metadata' => $line['metadata'] ?? null], $lines, array_keys($lines))); }
    private function validateReturnedQuantities(array $lines, ?PurchaseReturn $current = null): void { foreach ($lines as $line) { if ($line['vendor_bill_line_id']) { $billLine = VendorBillLine::query()->findOrFail((int) $line['vendor_bill_line_id']); $currentQty = $current ? (float) $current->lines()->where('vendor_bill_line_id', $billLine->id)->sum('quantity') : 0.0; if ((float) $line['quantity'] > (float) $billLine->quantity - (float) $billLine->returned_quantity + $currentQty) throw ApiException::make('RETURN_QUANTITY_EXCEEDS_BILLED', 'Return quantity exceeds billed quantity.', 422); } if ($line['goods_receipt_line_id']) { $receiptLine = GoodsReceiptLine::query()->findOrFail((int) $line['goods_receipt_line_id']); $currentQty = $current ? (float) $current->lines()->where('goods_receipt_line_id', $receiptLine->id)->sum('quantity') : 0.0; if ((float) $line['quantity'] > (float) $receiptLine->quantity - (float) $receiptLine->returned_quantity + $currentQty) throw ApiException::make('RETURN_QUANTITY_EXCEEDS_RECEIVED', 'Return quantity exceeds received quantity.', 422); } } }
    private function totals(array $lines): array { $subtotal = array_sum(array_map(fn ($line) => (float) $line['quantity'] * (float) $line['unit_price'], $lines)); $discount = array_sum(array_column($lines, 'discount_amount')); $tax = array_sum(array_column($lines, 'tax_amount')); $grand = array_sum(array_column($lines, 'line_total')); return ['subtotal_before_discount' => $subtotal, 'discount_total' => $discount, 'tax_total' => $tax, 'grand_total' => $grand]; }
    private function updateBill(PurchaseReturn $return): void { if ($return->vendor_bill_id) { $bill = VendorBill::query()->find($return->vendor_bill_id); if ($bill) { $bill->returned_amount = (float) $bill->returned_amount + (float) $return->grand_total; $bill->balance_due = max(0, (float) $bill->balance_due - (float) $return->grand_total); if ((float) $bill->balance_due <= 0) $bill->status = 'paid'; $bill->save(); } } foreach ($return->lines as $line) { if ($line->vendor_bill_line_id && ($billLine = VendorBillLine::query()->find($line->vendor_bill_line_id))) { $billLine->returned_quantity = (float) $billLine->returned_quantity + (float) $line->quantity; $billLine->save(); } if ($line->goods_receipt_line_id && ($receiptLine = GoodsReceiptLine::query()->find($line->goods_receipt_line_id))) { $receiptLine->returned_quantity = (float) $receiptLine->returned_quantity + (float) $line->quantity; $receiptLine->save(); } } }
    private function restoreSource(PurchaseReturn $return): void { if ($return->vendor_bill_id && ($bill = VendorBill::query()->lockForUpdate()->find($return->vendor_bill_id)) && $bill->status !== 'void') { $bill->returned_amount = max(0, (float) $bill->returned_amount - (float) $return->grand_total); $bill->balance_due = min((float) $bill->grand_total, (float) $bill->balance_due + (float) $return->grand_total); $bill->status = $bill->paid_amount > 0 ? 'partially_paid' : 'posted'; $bill->save(); } foreach ($return->lines as $line) { if ($line->vendor_bill_line_id && ($billLine = VendorBillLine::query()->lockForUpdate()->find($line->vendor_bill_line_id))) { $billLine->returned_quantity = max(0, (float) $billLine->returned_quantity - (float) $line->quantity); $billLine->save(); } if ($line->goods_receipt_line_id && ($receiptLine = GoodsReceiptLine::query()->lockForUpdate()->find($line->goods_receipt_line_id))) { $receiptLine->returned_quantity = max(0, (float) $receiptLine->returned_quantity - (float) $line->quantity); $receiptLine->save(); } } }
    private function journal(PurchaseReturn $return): JournalEntry { $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422); $journal = JournalEntry::query()->create(['journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $return->return_date), 'journal_date' => $return->return_date, 'description' => 'Purchase return '.$return->return_number, 'status' => 'posted', 'revision_no' => 1, 'source_type' => 'purchase_return', 'source_id' => $return->id, 'source_number' => $return->return_number, 'source_revision' => $return->revision_no, 'source_module' => 'purchase', 'is_system_generated' => true, 'created_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now()]); $lines = [['account_id' => $this->returnPayableAccountId($return), 'description' => 'Accounts Payable', 'debit' => $return->grand_total, 'credit' => 0, 'line_order' => 1], ['account_id' => $this->mapping('purchase.return'), 'description' => 'Purchase Return', 'debit' => 0, 'credit' => $return->grand_total - $return->tax_total, 'line_order' => 2]]; if ((float) $return->tax_total > 0) $lines[] = ['account_id' => $this->mapping('purchase.tax_input'), 'description' => 'Input Tax', 'debit' => 0, 'credit' => $return->tax_total, 'line_order' => 3]; $journal->lines()->createMany($lines); return $journal->refresh(); }
    private function returnPayableAccountId(PurchaseReturn $return): int { if ($return->vendor_bill_id && ($bill = VendorBill::query()->find($return->vendor_bill_id))) return $this->accountResolver->resolveBillPayableAccountId($bill); $vendor = $return->relationLoaded('vendor') ? $return->vendor : Contact::query()->find($return->vendor_id); if (! $vendor) throw ApiException::make('VENDOR_NOT_FOUND', 'Vendor not found.', 422); return $this->accountResolver->getPayableAccountId($vendor); }
    private function mapping(string $key): int { $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first(); if (! $mapping?->account_id) throw ApiException::make('ACCOUNT_MAPPING_MISSING', 'Required account mapping is missing: '.$key, 422); return (int) $mapping->account_id; }
    private function guardDate(string $date, string $action = 'post'): void { $check = $this->dateGuardService->check($date, $action, 'purchase'); if ($check->denied()) { $arr = $check->toArray(); throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']); } }
    private function assertMapping(string $key): void { $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first(); if (! $mapping?->account_id) throw ApiException::make('MAPPING_REQUIRED', "Account mapping [{$key}] is required for this operation.", 422, ['account_mapping' => ["{$key} is not configured"]]); }
}
