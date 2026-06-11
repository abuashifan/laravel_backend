<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesInvoiceLine;
use App\Models\Tenant\SalesReturn;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Inventory\InventorySalesIntegrationService;
use App\Services\Sales\Concerns\HandlesSalesDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesReturnService
{
    use HandlesSalesDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly InventorySalesIntegrationService $inventoryIntegration,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly SalesAccountResolverService $accountResolver,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}
    public function list(array $filters = []): Collection { $q = SalesReturn::query()->with('customer', 'salesInvoice'); if (! empty($filters['status'])) $q->where('status', (string) $filters['status']); return $q->orderByDesc('return_date')->orderByDesc('id')->get(); }
    public function find(int $id): SalesReturn { return SalesReturn::query()->with('lines', 'customer', 'salesInvoice', 'deliveryOrder')->findOrFail($id); }

    public function create(array $data): SalesReturn
    {
        $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureCustomerExists((int) $data['customer_id']);
        $lines = $this->normalizeReturnLines((array) $data['lines']);
        $this->validateReturnedQuantities($lines);
        $totals = $this->totals($lines);
        return DB::connection('tenant')->transaction(function () use ($company, $data, $lines, $totals) {
            $return = SalesReturn::query()->create(array_merge($this->guardedForHeader($data), $totals, ['return_number' => $this->documentNumberService->generate($company, DocumentType::SALES_RETURN, (string) $data['return_date']), 'status' => 'draft', 'created_by' => auth()->id()]));
            $return->lines()->createMany($lines);
            return $return->refresh()->load('lines', 'customer', 'salesInvoice');
        });
    }

    public function update(SalesReturn $return, array $data): SalesReturn
    {
        if ($return->status !== 'draft') throw ApiException::make('SALES_RETURN_NOT_EDITABLE', 'Sales return is not editable.', 422);
        $lines = $this->normalizeReturnLines((array) ($data['lines'] ?? $return->lines()->get()->toArray()));
        $this->validateReturnedQuantities($lines, $return);
        return DB::connection('tenant')->transaction(function () use ($return, $data, $lines) { $return->fill(array_merge($this->guardedForHeader($data), $this->totals($lines), ['revision_no' => (int) $return->revision_no + 1]))->save(); $return->lines()->delete(); $return->lines()->createMany($lines); return $return->refresh()->load('lines', 'customer', 'salesInvoice'); });
    }

    public function createFromSalesInvoice(SalesInvoice $invoice, array $overrides = []): SalesReturn
    {
        $invoice->loadMissing('lines');
        return $this->create(array_merge(['return_date' => now()->toDateString(), 'customer_id' => $invoice->customer_id, 'sales_invoice_id' => $invoice->id, 'currency_code' => $invoice->currency_code, 'exchange_rate' => $invoice->exchange_rate, 'source_type' => 'sales_invoice', 'source_id' => $invoice->id, 'source_number' => $invoice->invoice_number, 'source_revision' => $invoice->revision_no, 'lines' => $invoice->lines->map(fn ($line) => ['sales_invoice_line_id' => $line->id, 'product_id' => $line->product_id, 'product_code' => $line->product_code, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_id' => $line->unit_id, 'unit_price' => $line->unit_price, 'discount_amount' => $line->discount_amount, 'tax_amount' => $line->tax_amount, 'line_total' => $line->line_total, 'warehouse_id' => $line->warehouse_id, 'department_id' => $line->department_id, 'project_id' => $line->project_id, 'source_line_type' => 'sales_invoice_line', 'source_line_id' => $line->id, 'sort_order' => $line->sort_order])->toArray()], $overrides));
    }

    public function createFromDeliveryOrder(DeliveryOrder $deliveryOrder, array $overrides = []): SalesReturn
    {
        $deliveryOrder->loadMissing('lines');
        return $this->create(array_merge(['return_date' => now()->toDateString(), 'customer_id' => $deliveryOrder->customer_id, 'delivery_order_id' => $deliveryOrder->id, 'source_type' => 'delivery_order', 'source_id' => $deliveryOrder->id, 'source_number' => $deliveryOrder->delivery_number, 'source_revision' => $deliveryOrder->revision_no, 'lines' => $deliveryOrder->lines->map(fn ($line) => ['delivery_order_line_id' => $line->id, 'product_id' => $line->product_id, 'product_code' => $line->product_code, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_id' => $line->unit_id, 'unit_price' => 0, 'line_total' => 0, 'warehouse_id' => $line->warehouse_id, 'department_id' => $line->department_id, 'project_id' => $line->project_id, 'source_line_type' => 'delivery_order_line', 'source_line_id' => $line->id, 'sort_order' => $line->sort_order])->toArray()], $overrides));
    }

    public function approve(SalesReturn $return): SalesReturn { if ($return->status !== 'draft') throw ApiException::make('INVALID_SALES_RETURN_STATUS', 'Invalid sales return status transition.', 422); $return->status = 'approved'; $return->approved_by = auth()->id(); $return->approved_at = now(); $return->save(); return $return->refresh()->load('lines'); }
    public function post(SalesReturn $return): SalesReturn
    {
        if (! in_array($return->status, ['draft', 'approved'], true)) throw ApiException::make('INVALID_SALES_RETURN_STATUS', 'Sales return cannot be posted.', 422);
        $this->guardDate((string) $return->return_date);
        return DB::connection('tenant')->transaction(function () use ($return) { $return->load('lines'); $journal = $this->journal($return); $return->journal_entry_id = $journal->id; $return->status = 'posted'; $return->posted_by = auth()->id(); $return->posted_at = now(); $return->save(); $this->inventoryIntegration->createSalesReturnIn($return); $this->updateInvoice($return); return $return->refresh()->load('lines', 'customer', 'salesInvoice'); });
    }
    public function void(SalesReturn $return, ?string $reason = null): SalesReturn
    {
        if ($return->status === 'void') throw ApiException::make('SALES_RETURN_ALREADY_VOID', 'Sales return already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $return->return_date, 'void');
        return DB::connection('tenant')->transaction(function () use ($return, $reason) {
            $return->load('lines');
            $journalIds = $this->voidEffectService->voidJournalsForSource('sales_return', (int) $return->id, $reason);
            $movementIds = $this->voidEffectService->voidStockMovementsForSource('sales_return', (int) $return->id, $reason);
            if ($return->status === 'posted') $this->restoreInvoice($return);
            $return->status = 'void'; $return->voided_by = auth()->id(); $return->voided_at = now(); $return->void_reason = $reason; $return->save();
            $this->auditSales($this->auditLogService, 'sales_return.voided', 'sales', $return, 'return_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds, 'reversed_stock_movement_ids' => $movementIds]);
            return $return->refresh();
        });
    }

    private function normalizeReturnLines(array $lines): array { return array_values(array_map(fn (array $line, int $i): array => ['sales_invoice_line_id' => $line['sales_invoice_line_id'] ?? null, 'delivery_order_line_id' => $line['delivery_order_line_id'] ?? null, 'product_id' => $line['product_id'] ?? null, 'product_code' => $line['product_code'] ?? null, 'description' => $line['description'], 'quantity' => (float) $line['quantity'], 'unit_id' => $line['unit_id'] ?? null, 'unit_price' => (float) ($line['unit_price'] ?? 0), 'discount_amount' => (float) ($line['discount_amount'] ?? 0), 'tax_amount' => (float) ($line['tax_amount'] ?? 0), 'line_total' => (float) ($line['line_total'] ?? ((float) ($line['quantity'] ?? 0) * (float) ($line['unit_price'] ?? 0) - (float) ($line['discount_amount'] ?? 0) + (float) ($line['tax_amount'] ?? 0))), 'warehouse_id' => $line['warehouse_id'] ?? null, 'department_id' => $line['department_id'] ?? null, 'project_id' => $line['project_id'] ?? null, 'source_line_type' => $line['source_line_type'] ?? null, 'source_line_id' => $line['source_line_id'] ?? null, 'sort_order' => $line['sort_order'] ?? $i, 'metadata' => $line['metadata'] ?? null], $lines, array_keys($lines))); }
    private function validateReturnedQuantities(array $lines, ?SalesReturn $current = null): void { foreach ($lines as $line) { if (! $line['sales_invoice_line_id']) continue; $invoiceLine = SalesInvoiceLine::query()->findOrFail((int) $line['sales_invoice_line_id']); $currentQty = $current ? (float) $current->lines()->where('sales_invoice_line_id', $invoiceLine->id)->sum('quantity') : 0.0; if ((float) $line['quantity'] > (float) $invoiceLine->quantity - (float) $invoiceLine->returned_quantity + $currentQty) throw ApiException::make('RETURN_QUANTITY_EXCEEDS_INVOICED', 'Return quantity exceeds invoiced quantity.', 422); } }
    private function totals(array $lines): array { $subtotal = array_sum(array_map(fn ($line) => (float) $line['quantity'] * (float) $line['unit_price'], $lines)); $discount = array_sum(array_column($lines, 'discount_amount')); $tax = array_sum(array_column($lines, 'tax_amount')); $grand = array_sum(array_column($lines, 'line_total')); return ['subtotal_before_discount' => $subtotal, 'discount_total' => $discount, 'tax_total' => $tax, 'grand_total' => $grand]; }
    private function updateInvoice(SalesReturn $return): void { if (! $return->sales_invoice_id) return; $invoice = SalesInvoice::query()->find($return->sales_invoice_id); if (! $invoice) return; $invoice->returned_amount = (float) $invoice->returned_amount + (float) $return->grand_total; $invoice->balance_due = max(0, (float) $invoice->balance_due - (float) $return->grand_total); $invoice->save(); foreach ($return->lines as $line) { if ($line->sales_invoice_line_id) { $invoiceLine = SalesInvoiceLine::query()->find($line->sales_invoice_line_id); if ($invoiceLine) { $invoiceLine->returned_quantity = (float) $invoiceLine->returned_quantity + (float) $line->quantity; $invoiceLine->save(); } } } }
    private function restoreInvoice(SalesReturn $return): void { if (! $return->sales_invoice_id) return; $invoice = SalesInvoice::query()->lockForUpdate()->find($return->sales_invoice_id); if (! $invoice || $invoice->status === 'void') return; $invoice->returned_amount = max(0, (float) $invoice->returned_amount - (float) $return->grand_total); $invoice->balance_due = min((float) $invoice->grand_total, (float) $invoice->balance_due + (float) $return->grand_total); $invoice->save(); foreach ($return->lines as $line) { if ($line->sales_invoice_line_id && ($invoiceLine = SalesInvoiceLine::query()->lockForUpdate()->find($line->sales_invoice_line_id))) { $invoiceLine->returned_quantity = max(0, (float) $invoiceLine->returned_quantity - (float) $line->quantity); $invoiceLine->save(); } } }
    private function journal(SalesReturn $return): JournalEntry { $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422); $journal = JournalEntry::query()->create(['journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $return->return_date), 'journal_date' => $return->return_date, 'description' => 'Sales return '.$return->return_number, 'status' => 'posted', 'revision_no' => 1, 'source_type' => 'sales_return', 'source_id' => $return->id, 'source_number' => $return->return_number, 'source_revision' => $return->revision_no, 'source_module' => 'sales', 'is_system_generated' => true, 'created_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now()]); $lines = [['account_id' => $this->mapping('sales.return'), 'description' => 'Sales Return', 'debit' => $return->grand_total - $return->tax_total, 'credit' => 0, 'line_order' => 1]]; if ((float) $return->tax_total > 0) $lines[] = ['account_id' => $this->mapping('sales.tax_output'), 'description' => 'Output Tax', 'debit' => $return->tax_total, 'credit' => 0, 'line_order' => 2]; $ar = $return->sales_invoice_id ? $this->accountResolver->resolveInvoiceReceivableAccountId(SalesInvoice::query()->findOrFail((int) $return->sales_invoice_id)) : $this->mapping('sales.accounts_receivable'); $lines[] = ['account_id' => $ar, 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => $return->grand_total, 'line_order' => 3]; $journal->lines()->createMany($lines); return $journal->refresh(); }
    private function mapping(string $key): int { $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first(); if (! $mapping?->account_id) throw ApiException::make('ACCOUNT_MAPPING_MISSING', 'Required account mapping is missing: '.$key, 422); return (int) $mapping->account_id; }
    private function guardDate(string $date, string $action = 'post'): void { $check = $this->dateGuardService->check($date, $action, 'sales'); if ($check->denied()) { $arr = $check->toArray(); throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']); } }
}
