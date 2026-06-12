<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\DeliveryOrder;
use App\Models\Tenant\DeliveryOrderLine;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\ProformaInvoice;
use App\Models\Tenant\ProformaInvoiceLine;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\SalesReceipt;
use App\Models\Tenant\SalesReturn;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Inventory\InventorySalesIntegrationService;
use App\Services\Sales\Concerns\HandlesSalesDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\PaymentTermDueDateService;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Services\Validation\BusinessReferenceValidator;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesInvoiceService
{
    use HandlesSalesDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly SalesCalculationService $calculationService,
        private readonly PaymentTermDueDateService $paymentTermDueDateService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly InventorySalesIntegrationService $inventoryIntegration,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly SalesAccountResolverService $accountResolver,
        private readonly CustomerDepositService $depositService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = SalesInvoice::query()->with('customer', 'paymentTerm');
        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        return $query->orderByDesc('invoice_date')->orderByDesc('id')->get();
    }

    public function find(int $id): SalesInvoice
    {
        return $this->withAvailableDepositSummary(SalesInvoice::query()->with('lines.product', 'customer', 'paymentTerm', 'salesOrder', 'deliveryOrder', 'proformaInvoice')->findOrFail($id));
    }

    public function create(array $data): SalesInvoice
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $this->ensureCustomerExists((int) $data['customer_id']);
        app(BusinessReferenceValidator::class)->paymentTerm(isset($data['payment_term_id']) ? (int) $data['payment_term_id'] : null);
        $data = $this->paymentTermDueDateService->apply($data, 'invoice_date', (int) $data['customer_id']);

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $lines = $this->normalizeLines((array) $data['lines'], fn (array $line): array => [
                'sales_order_line_id' => $line['sales_order_line_id'] ?? null,
                'delivery_order_line_id' => $line['delivery_order_line_id'] ?? null,
                'proforma_invoice_line_id' => $line['proforma_invoice_line_id'] ?? null,
            ]);
            $lines = $this->withDraftRevenueSnapshots($lines);
            $totals = $this->calculationService->calculateDocument($lines, $data);
            $headerTotals = $totals;
            unset($headerTotals['lines']);
            $appliedDp = min((float) ($data['applied_down_payment_amount'] ?? 0), (float) $headerTotals['grand_total']);

            $invoice = SalesInvoice::query()->create(array_merge($this->guardedForHeader($data), $headerTotals, [
                'invoice_number' => $this->documentNumberService->generate($company, DocumentType::SALES_INVOICE, (string) $data['invoice_date']),
                'status' => 'draft',
                'applied_down_payment_amount' => $appliedDp,
                'paid_amount' => 0,
                'balance_due' => max(0, (float) $headerTotals['grand_total'] - $appliedDp),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]));
            $invoice->lines()->createMany($totals['lines']);

            $invoice = $invoice->refresh()->load('lines', 'customer', 'paymentTerm');
            $this->auditSales($this->auditLogService, 'sales_invoice.created', 'sales', $invoice, 'invoice_number');

            if ($this->shouldAutoPostOnCreateAccountingWorkflow()) {
                return $this->post($invoice);
            }

            return $this->withAvailableDepositSummary($invoice);
        });
    }

    public function update(SalesInvoice $invoice, array $data): SalesInvoice
    {
        if ($invoice->status !== 'draft') {
            throw ApiException::make('SALES_INVOICE_NOT_EDITABLE', 'Sales invoice status is not editable.', 422);
        }

        $data = $this->paymentTermDueDateService->apply(
            array_merge(['invoice_date' => $invoice->invoice_date?->toDateString()], $data),
            'invoice_date',
            (int) ($data['customer_id'] ?? $invoice->customer_id)
        );
        app(BusinessReferenceValidator::class)->paymentTerm(isset($data['payment_term_id']) ? (int) $data['payment_term_id'] : null);

        return DB::connection('tenant')->transaction(function () use ($invoice, $data) {
            $lines = $this->normalizeLines((array) ($data['lines'] ?? $invoice->lines()->get()->toArray()), fn (array $line): array => [
                'sales_order_line_id' => $line['sales_order_line_id'] ?? null,
                'delivery_order_line_id' => $line['delivery_order_line_id'] ?? null,
                'proforma_invoice_line_id' => $line['proforma_invoice_line_id'] ?? null,
                'revenue_account_id' => $line['revenue_account_id'] ?? null,
            ]);
            $lines = $this->withDraftRevenueSnapshots($lines);
            $totals = $this->calculationService->calculateDocument($lines, array_merge($invoice->toArray(), $data));
            $headerTotals = $totals;
            unset($headerTotals['lines']);
            $appliedDp = min((float) ($data['applied_down_payment_amount'] ?? $invoice->applied_down_payment_amount), (float) $headerTotals['grand_total']);

            $invoice->fill(array_merge($this->guardedForHeader($data), $headerTotals, [
                'applied_down_payment_amount' => $appliedDp,
                'balance_due' => max(0, (float) $headerTotals['grand_total'] - $appliedDp - (float) $invoice->paid_amount),
                'updated_by' => auth()->id(),
                'revision_no' => (int) $invoice->revision_no + 1,
            ]));
            $invoice->save();
            $invoice->lines()->delete();
            $invoice->lines()->createMany($totals['lines']);

            return $this->withAvailableDepositSummary($invoice->refresh()->load('lines', 'customer', 'paymentTerm'));
        });
    }

    public function createFromSalesOrder(SalesOrder $order, array $overrides = []): SalesInvoice
    {
        $this->guardConvertibleSource($order->status, 'sales order');
        $order->loadMissing('lines');
        $lines = $this->salesOrderInvoiceLines($order, (array) ($overrides['lines'] ?? []));
        $data = array_merge([
            'invoice_date' => now()->toDateString(),
            'customer_id' => $order->customer_id,
            'customer_address' => $order->customer_address,
            'sales_order_id' => $order->id,
            'salesperson_id' => $order->salesperson_id,
            'currency_code' => $order->currency_code,
            'exchange_rate' => $order->exchange_rate,
            'is_taxable' => $order->is_taxable,
            'tax_included' => $order->tax_included,
            'header_discount_type' => $order->header_discount_type,
            'header_discount_value' => $order->header_discount_value,
            'source_type' => 'sales_order',
            'source_id' => $order->id,
            'source_number' => $order->order_number,
            'source_revision' => $order->revision_no,
            'lines' => $lines,
        ], $overrides, ['lines' => $lines]);

        if (! array_key_exists('applied_down_payment_amount', $data)) {
            $data['applied_down_payment_amount'] = min($this->availableDownPaymentForOrder($order), $this->previewGrandTotal($data));
        }

        return $this->create($data);
    }

    public function createFromDeliveryOrder(DeliveryOrder $deliveryOrder, array $overrides = []): SalesInvoice
    {
        if (! in_array($deliveryOrder->status, ['delivered', 'partially_invoiced'], true)) {
            throw ApiException::make('DELIVERY_ORDER_NOT_DELIVERED', 'Only delivered delivery orders can be invoiced.', 422);
        }
        $deliveryOrder->loadMissing('lines');
        $lines = $this->deliveryOrderInvoiceLines($deliveryOrder, (array) ($overrides['lines'] ?? []));
        return $this->create(array_merge([
            'invoice_date' => now()->toDateString(),
            'customer_id' => $deliveryOrder->customer_id,
            'delivery_order_id' => $deliveryOrder->id,
            'sales_order_id' => $deliveryOrder->sales_order_id,
            'customer_address' => $deliveryOrder->shipping_address,
            'source_type' => 'delivery_order',
            'source_id' => $deliveryOrder->id,
            'source_number' => $deliveryOrder->delivery_number,
            'source_revision' => $deliveryOrder->revision_no,
            'lines' => $lines,
        ], $overrides, ['lines' => $lines]));
    }

    public function createFromProforma(ProformaInvoice $proforma, array $overrides = []): SalesInvoice
    {
        if (in_array($proforma->status, ['converted', 'cancelled'], true)) {
            throw ApiException::make('PROFORMA_NOT_CONVERTIBLE', 'Proforma invoice is not available for conversion.', 422);
        }
        $proforma->loadMissing('lines');
        return $this->create(array_merge([
            'invoice_date' => now()->toDateString(),
            'customer_id' => $proforma->customer_id,
            'customer_address' => $proforma->customer_address,
            'proforma_invoice_id' => $proforma->id,
            'salesperson_id' => $proforma->salesperson_id,
            'currency_code' => $proforma->currency_code,
            'exchange_rate' => $proforma->exchange_rate,
            'is_taxable' => $proforma->is_taxable,
            'tax_included' => $proforma->tax_included,
            'header_discount_type' => $proforma->header_discount_type,
            'header_discount_value' => $proforma->header_discount_value,
            'source_type' => 'proforma_invoice',
            'source_id' => $proforma->id,
            'source_number' => $proforma->proforma_number,
            'source_revision' => $proforma->revision_no,
            'lines' => $proforma->lines->map(fn ($line) => array_merge($line->only([
                'product_id', 'product_code', 'revenue_account_id', 'description', 'quantity', 'unit_id', 'unit_price',
                'discount_type', 'discount_value', 'tax_id', 'tax_rate', 'warehouse_id',
                'department_id', 'project_id', 'sort_order', 'metadata',
            ]), [
                'proforma_invoice_line_id' => $line->id,
                'source_line_type' => 'proforma_invoice_line',
                'source_line_id' => $line->id,
            ]))->toArray(),
        ], $overrides));
    }

    public function approve(SalesInvoice $invoice): SalesInvoice
    {
        if ($invoice->status !== 'draft') {
            throw ApiException::make('INVALID_SALES_INVOICE_STATUS', 'Invalid sales invoice status transition.', 422);
        }

        $invoice->status = 'approved';
        $invoice->approved_by = auth()->id();
        $invoice->approved_at = now();
        $invoice->save();

        return $invoice->refresh()->load('lines', 'customer');
    }

    public function post(SalesInvoice $invoice, ?float $appliedDownPaymentAmount = null): SalesInvoice
    {
        if (! in_array($invoice->status, ['draft', 'approved'], true)) {
            throw ApiException::make('INVALID_SALES_INVOICE_STATUS', 'Invoice cannot be posted from current status.', 422);
        }

        $dateCheck = $this->dateGuardService->check((string) $invoice->invoice_date, 'post', 'sales');
        if ($dateCheck->denied()) {
            $arr = $dateCheck->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }

        return DB::connection('tenant')->transaction(function () use ($invoice, $appliedDownPaymentAmount) {
            $invoice->load('lines', 'customer');
            $this->validateSourceRemainingQuantities($invoice);
            if (! $invoice->delivery_order_id) {
                $this->validateStockWarehousesForSalesLines($invoice->lines->toArray());
            }
            if ($appliedDownPaymentAmount !== null) {
                $invoice->applied_down_payment_amount = min($appliedDownPaymentAmount, (float) $invoice->grand_total);
            }

            $journal = $this->createInvoiceJournal($invoice);
            $invoice->journal_entry_id = $journal->id;

            if ((float) $invoice->applied_down_payment_amount > 0) {
                $allocationJournal = $this->applyAvailableDownPayment($invoice);
                $invoice->deposit_allocation_journal_entry_id = $allocationJournal?->id;
            }

            $invoice->paid_amount = (float) $invoice->applied_down_payment_amount;
            $invoice->balance_due = max(0, (float) $invoice->grand_total - (float) $invoice->paid_amount);
            $invoice->status = $invoice->balance_due <= 0 ? 'paid' : ((float) $invoice->paid_amount > 0 ? 'partially_paid' : 'posted');
            $invoice->posted_by = auth()->id();
            $invoice->posted_at = now();
            $invoice->save();

            $this->updateSourceProgress($invoice);
            $this->inventoryIntegration->createSalesOutFromSalesInvoice($invoice);
            $this->auditSales($this->auditLogService, 'sales_invoice.posted', 'sales', $invoice, 'invoice_number');

            return $this->withAvailableDepositSummary($invoice->refresh()->load('lines', 'customer'));
        });
    }

    public function void(SalesInvoice $invoice, ?string $reason = null): SalesInvoice
    {
        if ($invoice->status === 'void') {
            throw ApiException::make('SALES_INVOICE_ALREADY_VOID', 'Sales invoice already void.', 422);
        }
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardVoidDate((string) $invoice->invoice_date);

        if (SalesReceipt::query()->where('sales_invoice_id', $invoice->id)->where('status', 'posted')->exists()) {
            throw ApiException::make('SALES_INVOICE_HAS_RECEIPT', 'Void posted receipts before voiding this invoice.', 422);
        }
        if (SalesReturn::query()->where('sales_invoice_id', $invoice->id)->where('status', 'posted')->exists()) {
            throw ApiException::make('SALES_INVOICE_HAS_RETURN', 'Void posted returns before voiding this invoice.', 422);
        }

        return DB::connection('tenant')->transaction(function () use ($invoice, $reason) {
            $invoice->load('lines', 'proformaInvoice');
            $journalIds = $this->voidEffectService->voidJournalsForSource('sales_invoice', (int) $invoice->id, $reason);
            $movementIds = $this->voidEffectService->voidStockMovementsForSource('sales_invoice', (int) $invoice->id, $reason);
            $this->reverseDepositAllocations($invoice, $reason);
            $this->restoreSourceProgress($invoice);

            $invoice->status = 'void';
            $invoice->voided_by = auth()->id();
            $invoice->voided_at = now();
            $invoice->void_reason = $reason;
            $invoice->save();

            $this->auditSales($this->auditLogService, 'sales_invoice.voided', 'sales', $invoice, 'invoice_number', [
                'reason' => $reason,
                'voided_journal_ids' => $journalIds,
                'reversed_stock_movement_ids' => $movementIds,
            ]);

            return $invoice->refresh()->load('lines', 'customer');
        });
    }

    public function applyAvailableDownPayment(SalesInvoice $invoice): ?JournalEntry
    {
        if (! $invoice->sales_order_id || (float) $invoice->applied_down_payment_amount <= 0) {
            return null;
        }

        $remainingToApply = (float) $invoice->applied_down_payment_amount;
        $journal = $this->createDepositAllocationJournal($invoice, $remainingToApply);

        $deposits = CustomerDeposit::query()
            ->where('sales_order_id', $invoice->sales_order_id)
            ->whereIn('status', ['posted', 'partially_allocated'])
            ->where('remaining_amount', '>', 0)
            ->orderBy('deposit_date')
            ->get();

        foreach ($deposits as $deposit) {
            if ($remainingToApply <= 0) {
                break;
            }

            $amount = min($remainingToApply, (float) $deposit->remaining_amount);
            CustomerDepositAllocation::query()->create([
                'customer_deposit_id' => $deposit->id,
                'sales_invoice_id' => $invoice->id,
                'allocation_date' => $invoice->invoice_date,
                'allocated_amount' => $amount,
                'journal_entry_id' => $journal->id,
                'status' => 'posted',
                'created_by' => auth()->id(),
            ]);

            $deposit->allocated_amount = (float) $deposit->allocated_amount + $amount;
            $deposit->remaining_amount = (float) $deposit->remaining_amount - $amount;
            $deposit->status = $deposit->remaining_amount <= 0 ? 'fully_allocated' : 'partially_allocated';
            $deposit->save();
            $remainingToApply -= $amount;
        }

        if ($remainingToApply > 0.0001) {
            throw ApiException::make('CUSTOMER_DEPOSIT_INSUFFICIENT', 'Available customer deposit is insufficient.', 422);
        }

        return $journal;
    }

    private function createInvoiceJournal(SalesInvoice $invoice): JournalEntry
    {
        $ar = $this->accountResolver->resolveInvoiceReceivableAccountId($invoice);
        $revenueLines = $this->invoiceRevenueJournalLines($invoice);
        $tax = (float) $invoice->tax_total > 0 ? $this->requiredMapping('sales.tax_output') : null;

        $journal = $this->createJournal($invoice, 'Sales invoice '.$invoice->invoice_number);
        $lines = [
            ['account_id' => $ar, 'description' => 'Accounts Receivable', 'debit' => $invoice->grand_total, 'credit' => 0, 'line_order' => 1],
        ];
        foreach ($revenueLines as $line) {
            $lines[] = $line;
        }
        if ($tax && (float) $invoice->tax_total > 0) {
            $lines[] = ['account_id' => $tax, 'description' => 'Output Tax', 'debit' => 0, 'credit' => $invoice->tax_total, 'line_order' => count($lines) + 1];
        }
        $journal->lines()->createMany($lines);

        return $journal->refresh();
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    private function withDraftRevenueSnapshots(array $lines): array
    {
        return array_map(function (array $line): array {
            $line['revenue_account_id'] = $this->accountResolver->tryRevenueAccountIdForLine($line);

            return $line;
        }, $lines);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function invoiceRevenueJournalLines(SalesInvoice $invoice): array
    {
        $grouped = [];
        foreach ($invoice->lines as $line) {
            $accountId = $this->accountResolver->getRevenueAccountIdForLine($line);
            if ((int) $line->revenue_account_id !== $accountId) {
                $line->revenue_account_id = $accountId;
                $line->save();
            }
            $grouped[$accountId] = ($grouped[$accountId] ?? 0.0) + (float) $line->subtotal_after_discount;
        }

        $baseTotal = array_sum($grouped);
        if ($baseTotal > 0) {
            $targetTotal = (float) $invoice->subtotal_after_discount;
            $allocated = 0.0;
            $lastAccountId = array_key_last($grouped);
            foreach ($grouped as $accountId => $amount) {
                if ($accountId === $lastAccountId) {
                    $grouped[$accountId] = round($targetTotal - $allocated, 2);
                    continue;
                }
                $scaled = round($amount * ($targetTotal / $baseTotal), 2);
                $grouped[$accountId] = $scaled;
                $allocated += $scaled;
            }
        }

        $order = 2;
        return array_map(function (int $accountId, float $amount) use (&$order): array {
            return [
                'account_id' => $accountId,
                'description' => 'Sales Revenue',
                'debit' => 0,
                'credit' => $amount,
                'line_order' => $order++,
            ];
        }, array_keys($grouped), array_values($grouped));
    }

    private function createDepositAllocationJournal(SalesInvoice $invoice, float $amount): JournalEntry
    {
        $deposit = $this->requiredMapping('sales.customer_deposit');
        $ar = $this->accountResolver->resolveInvoiceReceivableAccountId($invoice);
        $journal = $this->createJournal($invoice, 'Apply customer deposit '.$invoice->invoice_number);
        $journal->lines()->createMany([
            ['account_id' => $deposit, 'description' => 'Customer Deposit', 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $ar, 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
        ]);

        return $journal->refresh();
    }

    private function createJournal(SalesInvoice $invoice, string $description): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        return JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $invoice->invoice_date),
            'journal_date' => $invoice->invoice_date,
            'description' => $description,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => 'sales_invoice',
            'source_id' => $invoice->id,
            'source_number' => $invoice->invoice_number,
            'source_revision' => $invoice->revision_no,
            'source_module' => 'sales',
            'is_system_generated' => true,
            'is_obsolete' => false,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);
    }

    private function requiredMapping(string $key): int
    {
        return app(BusinessReferenceValidator::class)->accountMapping($key, $key === 'sales.tax_output' ? ['liability'] : null);
    }

    private function updateSourceProgress(SalesInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if ($line->sales_order_line_id) {
                $orderLine = SalesOrderLine::query()->find($line->sales_order_line_id);
                if ($orderLine) {
                    $orderLine->invoiced_quantity = (float) $orderLine->invoiced_quantity + (float) $line->quantity;
                    $orderLine->save();
                }
            }
            if ($line->delivery_order_line_id) {
                $deliveryLine = DeliveryOrderLine::query()->find($line->delivery_order_line_id);
                if ($deliveryLine) {
                    $deliveryLine->invoiced_quantity = (float) $deliveryLine->invoiced_quantity + (float) $line->quantity;
                    $deliveryLine->save();
                }
            }
        }

        $this->refreshInvoiceSourceStatuses($invoice);

        if ($invoice->proformaInvoice) {
            $invoice->proformaInvoice->status = 'converted';
            $invoice->proformaInvoice->converted_by = auth()->id();
            $invoice->proformaInvoice->converted_at = now();
            $invoice->proformaInvoice->save();
        }
    }

    private function restoreSourceProgress(SalesInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if ($line->sales_order_line_id && ($orderLine = SalesOrderLine::query()->lockForUpdate()->find($line->sales_order_line_id))) {
                $orderLine->invoiced_quantity = max(0, (float) $orderLine->invoiced_quantity - (float) $line->quantity);
                $orderLine->save();
            }
            if ($line->delivery_order_line_id && ($deliveryLine = DeliveryOrderLine::query()->lockForUpdate()->find($line->delivery_order_line_id))) {
                $deliveryLine->invoiced_quantity = max(0, (float) $deliveryLine->invoiced_quantity - (float) $line->quantity);
                $deliveryLine->save();
            }
        }

        $this->refreshInvoiceSourceStatuses($invoice);

        if ($invoice->proformaInvoice && $invoice->proformaInvoice->status === 'converted') {
            $invoice->proformaInvoice->status = 'accepted';
            $invoice->proformaInvoice->save();
        }
    }

    private function reverseDepositAllocations(SalesInvoice $invoice, string $reason): void
    {
        $allocations = CustomerDepositAllocation::query()
            ->where('sales_invoice_id', $invoice->id)
            ->where('status', 'posted')
            ->get();

        foreach ($allocations as $allocation) {
            $deposit = CustomerDeposit::query()->lockForUpdate()->find($allocation->customer_deposit_id);
            if ($deposit) {
                $deposit->allocated_amount = max(0, (float) $deposit->allocated_amount - (float) $allocation->allocated_amount);
                $deposit->remaining_amount = (float) $deposit->remaining_amount + (float) $allocation->allocated_amount;
                $deposit->status = 'posted';
                $deposit->save();
            }
            $this->voidEffectService->voidJournalById((int) $allocation->journal_entry_id, $reason);
            $allocation->status = 'void';
            $allocation->voided_by = auth()->id();
            $allocation->voided_at = now();
            $allocation->void_reason = $reason;
            $allocation->save();
        }
    }

    private function guardVoidDate(string $date): void
    {
        $check = $this->dateGuardService->check($date, 'void', 'sales');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    private function availableDownPaymentForOrder(SalesOrder $order): float
    {
        return (float) CustomerDeposit::query()
            ->where('sales_order_id', $order->id)
            ->whereIn('status', ['posted', 'partially_allocated'])
            ->sum('remaining_amount');
    }

    private function previewGrandTotal(array $data): float
    {
        $lines = $this->normalizeLines((array) $data['lines']);
        $totals = $this->calculationService->calculateDocument($lines, $data);

        return (float) $totals['grand_total'];
    }

    private function salesOrderInvoiceLines(SalesOrder $order, array $requestedLines): array
    {
        $requested = collect($requestedLines)->keyBy(fn (array $line) => (string) ($line['sales_order_line_id'] ?? $line['source_line_id'] ?? ''));
        $lines = $order->lines->map(function ($line) use ($requested, $requestedLines): ?array {
            $remaining = max(0, (float) $line->quantity - (float) $line->invoiced_quantity);
            if ($remaining <= 0 || ($requestedLines !== [] && ! $requested->has((string) $line->id))) {
                return null;
            }
            $quantity = $requestedLines === [] ? $remaining : (float) ($requested->get((string) $line->id)['quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > $remaining) {
                throw ApiException::make('INVOICE_QUANTITY_EXCEEDS_REMAINING', 'Invoice quantity exceeds remaining sales order quantity.', 422);
            }

            return array_merge($line->only([
                'product_id', 'product_code', 'description', 'unit_id', 'unit_price',
                'discount_type', 'discount_value', 'tax_id', 'tax_rate', 'warehouse_id',
                'department_id', 'project_id', 'sort_order', 'metadata',
            ]), [
                'quantity' => $quantity,
                'sales_order_line_id' => $line->id,
                'source_line_type' => 'sales_order_line',
                'source_line_id' => $line->id,
            ]);
        })->filter()->values()->toArray();

        if ($lines === []) {
            throw ApiException::make('SALES_ORDER_ALREADY_INVOICED', 'Sales order has no remaining quantity to invoice.', 422);
        }

        return $lines;
    }

    private function deliveryOrderInvoiceLines(DeliveryOrder $deliveryOrder, array $requestedLines): array
    {
        $requested = collect($requestedLines)->keyBy(fn (array $line) => (string) ($line['delivery_order_line_id'] ?? $line['source_line_id'] ?? ''));
        $lines = $deliveryOrder->lines->map(function ($line) use ($requested, $requestedLines): ?array {
            $remaining = max(0, (float) $line->quantity - (float) $line->invoiced_quantity);
            if ($remaining <= 0 || ($requestedLines !== [] && ! $requested->has((string) $line->id))) {
                return null;
            }
            $quantity = $requestedLines === [] ? $remaining : (float) ($requested->get((string) $line->id)['quantity'] ?? 0);
            if ($quantity <= 0 || $quantity > $remaining) {
                throw ApiException::make('INVOICE_QUANTITY_EXCEEDS_REMAINING', 'Invoice quantity exceeds remaining delivered quantity.', 422);
            }
            $orderLine = $line->sales_order_line_id ? SalesOrderLine::query()->find($line->sales_order_line_id) : null;
            $proformaLine = ! $orderLine && $line->source_line_type === 'proforma_invoice_line' && $line->source_line_id
                ? ProformaInvoiceLine::query()->find($line->source_line_id)
                : null;
            if (! $orderLine && ! $proformaLine) {
                throw ApiException::make('DELIVERY_ORDER_PRICING_SOURCE_MISSING', 'Unable to resolve delivery line price from its sales order source.', 422);
            }

            return [
                'sales_order_line_id' => $line->sales_order_line_id,
                'delivery_order_line_id' => $line->id,
                'product_id' => $line->product_id,
                'product_code' => $line->product_code,
                'description' => $line->description,
                'quantity' => $quantity,
                'unit_id' => $line->unit_id,
                'unit_price' => $orderLine?->unit_price ?? $proformaLine?->unit_price ?? 0,
                'discount_type' => $orderLine?->discount_type ?? $proformaLine?->discount_type,
                'discount_value' => $orderLine?->discount_value ?? $proformaLine?->discount_value,
                'tax_id' => $orderLine?->tax_id ?? $proformaLine?->tax_id,
                'tax_rate' => $orderLine?->tax_rate ?? $proformaLine?->tax_rate,
                'warehouse_id' => $line->warehouse_id,
                'department_id' => $line->department_id,
                'project_id' => $line->project_id,
                'source_line_type' => 'delivery_order_line',
                'source_line_id' => $line->id,
                'sort_order' => $line->sort_order,
            ];
        })->filter()->values()->toArray();

        if ($lines === []) {
            throw ApiException::make('DELIVERY_ORDER_ALREADY_INVOICED', 'Delivery order has no remaining quantity to invoice.', 422);
        }

        return $lines;
    }

    private function validateSourceRemainingQuantities(SalesInvoice $invoice): void
    {
        foreach ($invoice->lines as $line) {
            if ($line->sales_order_line_id) {
                $source = SalesOrderLine::query()->lockForUpdate()->findOrFail($line->sales_order_line_id);
                if ((float) $line->quantity > (float) $source->quantity - (float) $source->invoiced_quantity) {
                    throw ApiException::make('INVOICE_QUANTITY_EXCEEDS_REMAINING', 'Invoice quantity exceeds remaining sales order quantity.', 422);
                }
            }
            if ($line->delivery_order_line_id) {
                $source = DeliveryOrderLine::query()->lockForUpdate()->findOrFail($line->delivery_order_line_id);
                if ((float) $line->quantity > (float) $source->quantity - (float) $source->invoiced_quantity) {
                    throw ApiException::make('INVOICE_QUANTITY_EXCEEDS_REMAINING', 'Invoice quantity exceeds remaining delivered quantity.', 422);
                }
            }
        }
        if ($invoice->proforma_invoice_id && ProformaInvoice::query()->lockForUpdate()->findOrFail($invoice->proforma_invoice_id)->status === 'converted') {
            throw ApiException::make('PROFORMA_ALREADY_CONVERTED', 'Proforma invoice has already been converted.', 422);
        }
    }

    private function refreshInvoiceSourceStatuses(SalesInvoice $invoice): void
    {
        if ($invoice->sales_order_id && ($order = SalesOrder::query()->with('lines')->find($invoice->sales_order_id))) {
            $total = (float) $order->lines->sum('quantity');
            $invoiced = (float) $order->lines->sum('invoiced_quantity');
            if ($invoiced > 0) {
                $order->status = $invoiced >= $total ? 'invoiced' : 'partially_invoiced';
                $order->invoiced_amount = $order->lines->sum(fn ($line) => min((float) $line->invoiced_quantity, (float) $line->quantity) * (float) $line->unit_price);
                $order->save();
            } elseif (in_array($order->status, ['invoiced', 'partially_invoiced'], true)) {
                $order->status = 'confirmed';
                $order->invoiced_amount = 0;
                $order->save();
            }
        }
        if ($invoice->delivery_order_id && ($delivery = DeliveryOrder::query()->with('lines')->find($invoice->delivery_order_id))) {
            $total = (float) $delivery->lines->sum('quantity');
            $invoiced = (float) $delivery->lines->sum('invoiced_quantity');
            if ($invoiced > 0) {
                $delivery->status = $invoiced >= $total ? 'invoiced' : 'partially_invoiced';
                $delivery->save();
            } elseif (in_array($delivery->status, ['invoiced', 'partially_invoiced'], true)) {
                $delivery->status = 'delivered';
                $delivery->save();
            }
        }
    }

    private function guardConvertibleSource(string $status, string $source): void
    {
        if (in_array($status, ['cancelled', 'void', 'closed'], true)) {
            throw ApiException::make('SOURCE_NOT_CONVERTIBLE', ucfirst($source).' is not available for conversion.', 422);
        }
    }

    private function withAvailableDepositSummary(SalesInvoice $invoice): SalesInvoice
    {
        if (! $invoice->customer_id) {
            return $invoice;
        }

        $invoice->setAttribute('available_deposit_summary', $this->depositService->availableForCustomer((int) $invoice->customer_id, [
            'sales_order_id' => $invoice->sales_order_id,
        ]));

        return $invoice;
    }
}
