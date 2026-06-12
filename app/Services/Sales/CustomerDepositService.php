<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesOrder;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\MasterData\AccountMappingStorageService;
use App\Services\Sales\Concerns\HandlesSalesDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class CustomerDepositService
{
    use HandlesSalesDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly SalesAccountResolverService $accountResolver,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = CustomerDeposit::query()->with('customer', 'salesOrder', 'cashBankAccount');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        return $query->orderByDesc('deposit_date')->orderByDesc('id')->get();
    }

    public function find(int $id): CustomerDeposit
    {
        return CustomerDeposit::query()->with('customer', 'salesOrder', 'cashBankAccount')->findOrFail($id);
    }

    public function availableForCustomer(int $customerId, array $filters = []): array
    {
        $salesOrderId = isset($filters['sales_order_id']) ? (int) $filters['sales_order_id'] : null;

        $deposits = CustomerDeposit::query()
            ->with('salesOrder')
            ->where('customer_id', $customerId)
            ->whereIn('status', ['posted', 'partially_allocated'])
            ->where('remaining_amount', '>', 0)
            ->when($salesOrderId, fn ($query) => $query->orderByRaw('case when sales_order_id = ? then 0 else 1 end', [$salesOrderId]))
            ->orderBy('deposit_date')
            ->orderBy('id')
            ->get()
            ->map(fn (CustomerDeposit $deposit): array => $this->availableDepositRow($deposit, $salesOrderId))
            ->values()
            ->all();

        return [
            'customer_id' => $customerId,
            'unapplied_total' => round((float) collect($deposits)->sum('remaining_amount'), 2),
            'deposits' => $deposits,
        ];
    }

    public function availableForSalesOrder(int $salesOrderId): array
    {
        $order = SalesOrder::query()->findOrFail($salesOrderId);

        return $this->availableForCustomer((int) $order->customer_id, ['sales_order_id' => $order->id]);
    }

    public function availableForInvoice(int $salesInvoiceId): array
    {
        $invoice = SalesInvoice::query()->findOrFail($salesInvoiceId);

        return $this->availableForCustomer((int) $invoice->customer_id, [
            'sales_order_id' => $invoice->sales_order_id,
        ]);
    }

    public function calculateUnappliedTotalForCustomer(int $customerId): float
    {
        return round((float) CustomerDeposit::query()
            ->where('customer_id', $customerId)
            ->whereIn('status', ['posted', 'partially_allocated'])
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount'), 2);
    }

    public function create(array $data): CustomerDeposit
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $amount = (float) $data['amount'];
        $this->ensureCustomerExists((int) $data['customer_id']);
        $deposit = CustomerDeposit::query()->create(array_merge($data, [
            'deposit_number' => $this->documentNumberService->generate($company, DocumentType::CUSTOMER_DEPOSIT, (string) $data['deposit_date']),
            'remaining_amount' => $amount,
            'allocated_amount' => 0,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]))->refresh();

        $deposit = $deposit->load('customer', 'salesOrder', 'cashBankAccount');

        return $this->shouldAutoPostOnCreateAccountingWorkflow() ? $this->post($deposit) : $deposit;
    }

    public function createFromSalesOrder(SalesOrder $order, array $depositData): CustomerDeposit
    {
        return $this->create(array_merge($depositData, [
            'customer_id' => $order->customer_id,
            'sales_order_id' => $order->id,
            'currency_code' => $order->currency_code,
            'exchange_rate' => $order->exchange_rate,
            'source_type' => 'sales_order',
            'source_id' => $order->id,
            'source_number' => $order->order_number,
            'source_revision' => $order->revision_no,
        ]));
    }

    public function post(CustomerDeposit $deposit): CustomerDeposit
    {
        if ($deposit->status === 'posted') return $deposit;
        $this->guardDate((string) $deposit->deposit_date);
        return DB::connection('tenant')->transaction(function () use ($deposit) {
            $journal = $this->journal($deposit, 'Customer deposit '.$deposit->deposit_number, [
                ['account_id' => $deposit->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => $deposit->amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $this->mapping('sales.customer_deposit'), 'description' => 'Customer Deposit', 'debit' => 0, 'credit' => $deposit->amount, 'line_order' => 2],
            ]);
            $deposit->status = 'posted';
            $deposit->journal_entry_id = $journal->id;
            $deposit->posted_by = auth()->id();
            $deposit->posted_at = now();
            $deposit->remaining_amount = $deposit->amount;
            $deposit->save();
            return $deposit->refresh()->load('customer', 'salesOrder', 'cashBankAccount');
        });
    }

    public function void(CustomerDeposit $deposit, ?string $reason = null): CustomerDeposit
    {
        if ($deposit->status === 'void') throw ApiException::make('CUSTOMER_DEPOSIT_ALREADY_VOID', 'Customer deposit already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $deposit->deposit_date, 'void');
        return DB::connection('tenant')->transaction(function () use ($deposit, $reason) {
            $journalIds = $this->voidEffectService->voidJournalsForSource('customer_deposit', (int) $deposit->id, $reason);
            $allocations = CustomerDepositAllocation::query()->where('customer_deposit_id', $deposit->id)->where('status', 'posted')->get();
            foreach ($allocations as $allocation) {
                $invoice = SalesInvoice::query()->lockForUpdate()->find($allocation->sales_invoice_id);
                if ($invoice && $invoice->status !== 'void') {
                    $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $allocation->allocated_amount);
                    $invoice->balance_due = min((float) $invoice->grand_total, (float) $invoice->balance_due + (float) $allocation->allocated_amount);
                    $invoice->status = $invoice->paid_amount > 0 ? 'partially_paid' : 'posted';
                    $invoice->save();
                }
                $journalId = $this->voidEffectService->voidJournalById((int) $allocation->journal_entry_id, $reason);
                if ($journalId) $journalIds[] = $journalId;
                $allocation->status = 'void'; $allocation->voided_by = auth()->id(); $allocation->voided_at = now(); $allocation->void_reason = $reason; $allocation->save();
            }
            $deposit->status = 'void'; $deposit->voided_by = auth()->id(); $deposit->voided_at = now(); $deposit->void_reason = $reason; $deposit->save();
            $this->auditSales($this->auditLogService, 'customer_deposit.voided', 'sales', $deposit, 'deposit_number', ['reason' => $reason, 'voided_journal_ids' => array_values(array_unique($journalIds)), 'voided_allocation_ids' => $allocations->pluck('id')->all()]);
            return $deposit->refresh()->load('customer', 'salesOrder', 'cashBankAccount');
        });
    }

    public function refund(CustomerDeposit $deposit, float $amount, ?string $reason = null): CustomerDeposit
    {
        if ($amount > (float) $deposit->remaining_amount) throw ApiException::make('REFUND_EXCEEDS_REMAINING_DEPOSIT', 'Refund exceeds remaining deposit.', 422);
        $this->guardDate((string) $deposit->deposit_date);
        return DB::connection('tenant')->transaction(function () use ($deposit, $amount, $reason) {
            $journal = $this->journal($deposit, 'Refund customer deposit '.$deposit->deposit_number, [
                ['account_id' => $this->mapping('sales.customer_deposit'), 'description' => 'Customer Deposit', 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $deposit->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ]);
            $deposit->remaining_amount = (float) $deposit->remaining_amount - $amount;
            $deposit->refund_journal_entry_id = $journal->id;
            $deposit->status = $deposit->remaining_amount <= 0 ? 'refunded' : 'partially_allocated';
            $deposit->refunded_by = auth()->id(); $deposit->refunded_at = now(); $deposit->refund_reason = $reason; $deposit->save();
            return $deposit->refresh()->load('customer', 'salesOrder', 'cashBankAccount');
        });
    }

    public function allocateToInvoice(CustomerDeposit $deposit, SalesInvoice $invoice, float $amount, array $options = []): CustomerDepositAllocation
    {
        if ($deposit->customer_id !== $invoice->customer_id) throw ApiException::make('CUSTOMER_MISMATCH', 'Deposit and invoice customer mismatch.', 422);
        if (! in_array($deposit->status, ['posted', 'partially_allocated'], true)) throw ApiException::make('CUSTOMER_DEPOSIT_NOT_AVAILABLE', 'Customer deposit is not available for allocation.', 422);
        if (! in_array($invoice->status, ['posted', 'partially_paid'], true) || ! $invoice->posted_at) throw ApiException::make('SALES_INVOICE_NOT_PAYABLE', 'Sales invoice must be posted before deposit allocation.', 422);
        if ($amount > (float) $deposit->remaining_amount) throw ApiException::make('CUSTOMER_DEPOSIT_INSUFFICIENT', 'Cannot allocate more than remaining deposit.', 422);
        if ($amount > (float) $invoice->balance_due) throw ApiException::make('CUSTOMER_DEPOSIT_ALLOCATION_EXCEEDS_INVOICE_BALANCE', 'Cannot allocate more than invoice balance due.', 422);
        $allocationDate = (string) ($options['allocation_date'] ?? $invoice->invoice_date);
        $this->guardDate($allocationDate);

        return DB::connection('tenant')->transaction(function () use ($deposit, $invoice, $amount, $allocationDate, $options) {
            $lockedDeposit = CustomerDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            $lockedInvoice = SalesInvoice::query()->lockForUpdate()->findOrFail($invoice->id);

            if ($lockedDeposit->customer_id !== $lockedInvoice->customer_id) throw ApiException::make('CUSTOMER_MISMATCH', 'Deposit and invoice customer mismatch.', 422);
            if (! in_array($lockedDeposit->status, ['posted', 'partially_allocated'], true)) throw ApiException::make('CUSTOMER_DEPOSIT_NOT_AVAILABLE', 'Customer deposit is not available for allocation.', 422);
            if (! in_array($lockedInvoice->status, ['posted', 'partially_paid'], true) || ! $lockedInvoice->posted_at) throw ApiException::make('SALES_INVOICE_NOT_PAYABLE', 'Sales invoice must be posted before deposit allocation.', 422);
            if ($amount > (float) $lockedDeposit->remaining_amount) throw ApiException::make('CUSTOMER_DEPOSIT_INSUFFICIENT', 'Cannot allocate more than remaining deposit.', 422);
            if ($amount > (float) $lockedInvoice->balance_due) throw ApiException::make('CUSTOMER_DEPOSIT_ALLOCATION_EXCEEDS_INVOICE_BALANCE', 'Cannot allocate more than invoice balance due.', 422);

            $journal = $this->journal($deposit, 'Apply customer deposit '.$invoice->invoice_number, [
                ['account_id' => $this->mapping('sales.customer_deposit'), 'description' => 'Customer Deposit', 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $this->accountResolver->resolveInvoiceReceivableAccountId($lockedInvoice), 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ], $lockedInvoice, $allocationDate);

            $metadata = array_filter([
                'source_context' => $options['source_context'] ?? null,
                'notes' => $options['notes'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $allocation = CustomerDepositAllocation::query()->create([
                'customer_deposit_id' => $lockedDeposit->id,
                'sales_invoice_id' => $lockedInvoice->id,
                'allocation_date' => $allocationDate,
                'allocated_amount' => $amount,
                'journal_entry_id' => $journal->id,
                'status' => 'posted',
                'metadata' => $metadata === [] ? null : $metadata,
                'created_by' => auth()->id(),
            ]);
            $journal->source_id = $allocation->id;
            $journal->save();

            $lockedDeposit->allocated_amount = (float) $lockedDeposit->allocated_amount + $amount; $lockedDeposit->remaining_amount = (float) $lockedDeposit->remaining_amount - $amount; $lockedDeposit->status = $lockedDeposit->remaining_amount <= 0 ? 'fully_allocated' : 'partially_allocated'; $lockedDeposit->save();
            $lockedInvoice->paid_amount = (float) $lockedInvoice->paid_amount + $amount; $lockedInvoice->balance_due = max(0, (float) $lockedInvoice->balance_due - $amount); $lockedInvoice->status = $lockedInvoice->balance_due <= 0 ? 'paid' : 'partially_paid'; $lockedInvoice->save();
            $this->auditSales($this->auditLogService, 'customer_deposit.allocated', 'sales', $lockedDeposit, 'deposit_number', ['allocation_id' => $allocation->id, 'sales_invoice_id' => $lockedInvoice->id, 'amount' => $amount, 'source_context' => $options['source_context'] ?? null]);

            return $allocation->refresh()->load('customerDeposit', 'salesInvoice');
        });
    }

    public function voidAllocation(CustomerDepositAllocation $allocation, ?string $reason = null): CustomerDepositAllocation
    {
        if ($allocation->status === 'void') throw ApiException::make('CUSTOMER_DEPOSIT_ALLOCATION_ALREADY_VOID', 'Customer deposit allocation already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $allocation->allocation_date, 'void');

        return DB::connection('tenant')->transaction(function () use ($allocation, $reason) {
            $lockedAllocation = CustomerDepositAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $deposit = CustomerDeposit::query()->lockForUpdate()->findOrFail($lockedAllocation->customer_deposit_id);
            $invoice = SalesInvoice::query()->lockForUpdate()->find($lockedAllocation->sales_invoice_id);
            $amount = (float) $lockedAllocation->allocated_amount;

            if ($invoice && $invoice->status !== 'void') {
                $invoice->paid_amount = max(0, (float) $invoice->paid_amount - $amount);
                $invoice->balance_due = min((float) $invoice->grand_total, (float) $invoice->balance_due + $amount);
                $invoice->status = $invoice->paid_amount > 0 ? 'partially_paid' : 'posted';
                $invoice->save();
            }

            $deposit->allocated_amount = max(0, (float) $deposit->allocated_amount - $amount);
            $deposit->remaining_amount = (float) $deposit->remaining_amount + $amount;
            $deposit->status = $deposit->allocated_amount <= 0 ? 'posted' : 'partially_allocated';
            $deposit->save();

            $this->voidEffectService->voidJournalById((int) $lockedAllocation->journal_entry_id, $reason);
            $lockedAllocation->status = 'void';
            $lockedAllocation->voided_by = auth()->id();
            $lockedAllocation->voided_at = now();
            $lockedAllocation->void_reason = $reason;
            $lockedAllocation->save();

            return $lockedAllocation->refresh();
        });
    }

    public function calculateAvailableForSalesOrder(SalesOrder $order): float { return (float) CustomerDeposit::query()->where('sales_order_id', $order->id)->whereIn('status', ['posted', 'partially_allocated'])->sum('remaining_amount'); }
    public function calculateAvailableForCustomer(int $customerId): float { return (float) CustomerDeposit::query()->where('customer_id', $customerId)->whereIn('status', ['posted', 'partially_allocated'])->sum('remaining_amount'); }
    public function calculateReceivedForSalesOrder(SalesOrder $order): float { return (float) $order->deposits()->where('status', '!=', 'void')->sum('amount'); }

    private function mapping(string $key): int { app(AccountMappingStorageService::class)->syncDefaultMappingsFromConfig(); $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first(); if (! $mapping?->account_id) throw ApiException::make('ACCOUNT_MAPPING_MISSING', $key === 'sales.customer_deposit' ? 'Mapping akun Uang Muka Pelanggan belum diatur. Silakan atur sales.customer_deposit di Pemetaan Akun.' : 'Required account mapping is missing: '.$key, 422); return (int) $mapping->account_id; }
    private function guardDate(string $date, string $action = 'post'): void { $check = $this->dateGuardService->check($date, $action, 'sales'); if ($check->denied()) { $arr = $check->toArray(); throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']); } }
    private function availableDepositRow(CustomerDeposit $deposit, ?int $salesOrderId = null): array
    {
        $matchesSalesOrder = $salesOrderId !== null && (int) $deposit->sales_order_id === $salesOrderId;

        return [
            'id' => $deposit->id,
            'deposit_number' => $deposit->deposit_number,
            'deposit_date' => optional($deposit->deposit_date)->toDateString(),
            'customer_id' => $deposit->customer_id,
            'amount' => (float) $deposit->amount,
            'allocated_amount' => (float) $deposit->allocated_amount,
            'remaining_amount' => (float) $deposit->remaining_amount,
            'sales_order_id' => $deposit->sales_order_id,
            'sales_order_number' => $deposit->salesOrder?->order_number,
            'match_strength' => $matchesSalesOrder ? 'sales_order' : 'customer_only',
        ];
    }
    private function journal(CustomerDeposit $deposit, string $description, array $lines, ?SalesInvoice $invoice = null, ?string $journalDate = null): JournalEntry { $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422); $date = $journalDate ?? (string) ($invoice?->invoice_date ?? $deposit->deposit_date); $journal = JournalEntry::query()->create(['journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, $date), 'journal_date' => $date, 'description' => $description, 'status' => 'posted', 'revision_no' => 1, 'source_type' => $invoice ? 'customer_deposit_allocation' : 'customer_deposit', 'source_id' => $invoice?->id ?? $deposit->id, 'source_number' => $invoice?->invoice_number ?? $deposit->deposit_number, 'source_revision' => $invoice?->revision_no ?? 1, 'source_module' => 'sales', 'is_system_generated' => true, 'created_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now()]); $journal->lines()->createMany($lines); return $journal->refresh(); }
}
