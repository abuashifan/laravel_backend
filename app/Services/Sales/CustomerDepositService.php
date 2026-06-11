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
        $query = CustomerDeposit::query()->with('customer', 'salesOrder');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        return $query->orderByDesc('deposit_date')->orderByDesc('id')->get();
    }

    public function find(int $id): CustomerDeposit
    {
        return CustomerDeposit::query()->with('customer', 'salesOrder')->findOrFail($id);
    }

    public function create(array $data): CustomerDeposit
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $amount = (float) $data['amount'];
        $this->ensureCustomerExists((int) $data['customer_id']);
        return CustomerDeposit::query()->create(array_merge($data, [
            'deposit_number' => $this->documentNumberService->generate($company, DocumentType::CUSTOMER_DEPOSIT, (string) $data['deposit_date']),
            'remaining_amount' => $amount,
            'allocated_amount' => 0,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]))->refresh();
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
            return $deposit->refresh();
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
            return $deposit->refresh();
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
            return $deposit->refresh();
        });
    }

    public function allocateToInvoice(CustomerDeposit $deposit, SalesInvoice $invoice, float $amount): CustomerDepositAllocation
    {
        if ($deposit->customer_id !== $invoice->customer_id) throw ApiException::make('CUSTOMER_MISMATCH', 'Deposit and invoice customer mismatch.', 422);
        if ($amount > (float) $deposit->remaining_amount) throw ApiException::make('CUSTOMER_DEPOSIT_INSUFFICIENT', 'Cannot allocate more than remaining deposit.', 422);
        $this->guardDate((string) $invoice->invoice_date);
        return DB::connection('tenant')->transaction(function () use ($deposit, $invoice, $amount) {
            $journal = $this->journal($deposit, 'Apply customer deposit '.$invoice->invoice_number, [
                ['account_id' => $this->mapping('sales.customer_deposit'), 'description' => 'Customer Deposit', 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $this->accountResolver->resolveInvoiceReceivableAccountId($invoice), 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ], $invoice);
            $allocation = CustomerDepositAllocation::query()->create(['customer_deposit_id' => $deposit->id, 'sales_invoice_id' => $invoice->id, 'allocation_date' => $invoice->invoice_date, 'allocated_amount' => $amount, 'journal_entry_id' => $journal->id, 'status' => 'posted', 'created_by' => auth()->id()]);
            $deposit->allocated_amount = (float) $deposit->allocated_amount + $amount; $deposit->remaining_amount = (float) $deposit->remaining_amount - $amount; $deposit->status = $deposit->remaining_amount <= 0 ? 'fully_allocated' : 'partially_allocated'; $deposit->save();
            $invoice->paid_amount = (float) $invoice->paid_amount + $amount; $invoice->balance_due = max(0, (float) $invoice->balance_due - $amount); $invoice->status = $invoice->balance_due <= 0 ? 'paid' : 'partially_paid'; $invoice->save();
            return $allocation;
        });
    }

    public function calculateAvailableForSalesOrder(SalesOrder $order): float { return (float) CustomerDeposit::query()->where('sales_order_id', $order->id)->whereIn('status', ['posted', 'partially_allocated'])->sum('remaining_amount'); }
    public function calculateAvailableForCustomer(int $customerId): float { return (float) CustomerDeposit::query()->where('customer_id', $customerId)->whereIn('status', ['posted', 'partially_allocated'])->sum('remaining_amount'); }
    public function calculateReceivedForSalesOrder(SalesOrder $order): float { return (float) $order->deposits()->where('status', '!=', 'void')->sum('amount'); }

    private function mapping(string $key): int { $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first(); if (! $mapping?->account_id) throw ApiException::make('ACCOUNT_MAPPING_MISSING', 'Required account mapping is missing: '.$key, 422); return (int) $mapping->account_id; }
    private function guardDate(string $date, string $action = 'post'): void { $check = $this->dateGuardService->check($date, $action, 'sales'); if ($check->denied()) { $arr = $check->toArray(); throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']); } }
    private function journal(CustomerDeposit $deposit, string $description, array $lines, ?SalesInvoice $invoice = null): JournalEntry { $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422); $journal = JournalEntry::query()->create(['journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) ($invoice?->invoice_date ?? $deposit->deposit_date)), 'journal_date' => $invoice?->invoice_date ?? $deposit->deposit_date, 'description' => $description, 'status' => 'posted', 'revision_no' => 1, 'source_type' => $invoice ? 'sales_invoice' : 'customer_deposit', 'source_id' => $invoice?->id ?? $deposit->id, 'source_number' => $invoice?->invoice_number ?? $deposit->deposit_number, 'source_revision' => $invoice?->revision_no ?? 1, 'source_module' => 'sales', 'is_system_generated' => true, 'created_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now()]); $journal->lines()->createMany($lines); return $journal->refresh(); }
}
