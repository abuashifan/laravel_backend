<?php

namespace App\Services\Sales;

use App\Exceptions\ApiException;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesReceipt;
use App\Services\Audit\AuditLogService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Sales\Concerns\HandlesSalesDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Services\Validation\BusinessReferenceValidator;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesReceiptService
{
    use HandlesSalesDocuments;

    public function __construct(private readonly TenantContext $tenantContext, private readonly DocumentNumberService $documentNumberService, private readonly TransactionDateGuardService $dateGuardService, private readonly TransactionVoidEffectService $voidEffectService, private readonly SalesAccountResolverService $accountResolver, private readonly CustomerDepositService $depositService, private readonly ARSubsidiaryLedgerService $ledgerService, private readonly ?AuditLogService $auditLogService = null) {}
    public function list(array $filters = []): Collection { $q = SalesReceipt::query()->with('customer', 'salesInvoice'); if (! empty($filters['status'])) $q->where('status', (string) $filters['status']); return $q->orderByDesc('receipt_date')->orderByDesc('id')->get(); }
    public function find(int $id): SalesReceipt { return SalesReceipt::query()->with('lines', 'customer', 'salesInvoice')->findOrFail($id); }

    public function create(array $data): SalesReceipt
    {
        $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureCustomerExists((int) $data['customer_id']);
        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $receipt = SalesReceipt::query()->create(array_merge($data, ['receipt_number' => $this->documentNumberService->generate($company, DocumentType::SALES_RECEIPT, (string) $data['receipt_date']), 'status' => 'draft', 'created_by' => auth()->id()]));
            $receipt->lines()->createMany($data['lines'] ?? [['sales_invoice_id' => $data['sales_invoice_id'] ?? null, 'amount' => $data['amount'], 'description' => $data['notes'] ?? null]]);
            $receipt = $receipt->refresh()->load('lines', 'customer', 'salesInvoice');
            return $this->shouldAutoPostOnCreateAccountingWorkflow() ? $this->post($receipt) : $receipt;
        });
    }

    public function post(SalesReceipt $receipt): SalesReceipt
    {
        if ($receipt->status === 'posted') throw ApiException::make('DOCUMENT_ALREADY_POSTED', 'Document has already been posted.', 422);
        $this->guardDate((string) $receipt->receipt_date);
        $allocations = $this->validatedAllocations($receipt);
        return DB::connection('tenant')->transaction(function () use ($receipt, $allocations) {
            $journal = $this->journal($receipt, $allocations);
            $receipt->status = 'posted'; $receipt->journal_entry_id = $journal->id; $receipt->posted_by = auth()->id(); $receipt->posted_at = now(); $receipt->save();
            foreach ($allocations as $allocation) {
                $this->applyToInvoiceAmount($allocation['invoice'], $allocation['amount']);
            }
            return $receipt->refresh()->load('lines', 'customer', 'salesInvoice');
        });
    }

    public function void(SalesReceipt $receipt, ?string $reason = null): SalesReceipt
    {
        if ($receipt->status === 'void') throw ApiException::make('SALES_RECEIPT_ALREADY_VOID', 'Sales receipt already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $receipt->receipt_date, 'void');
        return DB::connection('tenant')->transaction(function () use ($receipt, $reason) {
            $journalIds = $this->voidEffectService->voidJournalsForSource('sales_receipt', (int) $receipt->id, $reason);
            if ($receipt->status === 'posted') {
                $receipt->loadMissing('lines');
                foreach ($receipt->lines as $line) {
                    if (! $line->sales_invoice_id) continue;
                    $invoice = SalesInvoice::query()->lockForUpdate()->find($line->sales_invoice_id);
                    if ($invoice && $invoice->status !== 'void') {
                        $amount = (float) $line->amount;
                        $invoice->paid_amount = max(0, (float) $invoice->paid_amount - $amount);
                        $invoice->balance_due = min((float) $invoice->grand_total, (float) $invoice->balance_due + $amount);
                        $invoice->status = $invoice->paid_amount > 0 ? 'partially_paid' : 'posted';
                        $invoice->save();
                    }
                }
            }
            $receipt->status = 'void'; $receipt->voided_by = auth()->id(); $receipt->voided_at = now(); $receipt->void_reason = $reason; $receipt->save();
            $this->auditSales($this->auditLogService, 'sales_receipt.voided', 'sales', $receipt, 'receipt_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds]);
            return $receipt->refresh();
        });
    }

    public function applyToInvoice(SalesReceipt $receipt, SalesInvoice $invoice): void
    {
        $this->applyToInvoiceAmount($invoice, (float) $receipt->amount);
    }

    public function applyToInvoiceAmount(SalesInvoice $invoice, float $amount): void
    {
        $invoice->paid_amount = (float) $invoice->paid_amount + $amount;
        $invoice->balance_due = max(0, (float) $invoice->balance_due - $amount);
        $invoice->status = $invoice->balance_due <= 0 ? 'paid' : 'partially_paid';
        $invoice->save();
    }

    public function customerContext(int $customerId): array
    {
        $openInvoices = $this->ledgerService->openInvoices(['customer_id' => $customerId]);
        $available = $this->depositService->availableForCustomer($customerId);
        $officialArBalance = round((float) collect($openInvoices)->sum('balance_due'), 2);
        $unappliedDeposit = (float) $available['unapplied_total'];

        return [
            'customer_id' => $customerId,
            'gross_ar_outstanding' => $officialArBalance,
            'official_ar_balance' => $officialArBalance,
            'unapplied_deposit_total' => $unappliedDeposit,
            'net_customer_exposure' => round($officialArBalance - $unappliedDeposit, 2),
            'open_invoices' => $openInvoices,
            'available_deposits' => $available['deposits'],
        ];
    }

    public function updateInvoicePaymentStatus(SalesInvoice $invoice): SalesInvoice { $invoice->status = (float) $invoice->balance_due <= 0 ? 'paid' : ((float) $invoice->paid_amount > 0 ? 'partially_paid' : $invoice->status); $invoice->save(); return $invoice->refresh(); }
    private function guardDate(string $date, string $action = 'post'): void { $check = $this->dateGuardService->check($date, $action, 'sales'); if ($check->denied()) { $arr = $check->toArray(); throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']); } }
    private function journal(SalesReceipt $receipt, array $allocations): JournalEntry { $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422); $journal = JournalEntry::query()->create(['journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $receipt->receipt_date), 'journal_date' => $receipt->receipt_date, 'description' => 'Sales receipt '.$receipt->receipt_number, 'status' => 'posted', 'revision_no' => 1, 'source_type' => 'sales_receipt', 'source_id' => $receipt->id, 'source_number' => $receipt->receipt_number, 'source_revision' => 1, 'source_module' => 'sales', 'is_system_generated' => true, 'created_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now()]); $lines = [['account_id' => $receipt->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => $receipt->amount, 'credit' => 0, 'line_order' => 1]]; $grouped = []; foreach ($allocations as $allocation) { $ar = $this->accountResolver->resolveInvoiceReceivableAccountId($allocation['invoice']); $grouped[$ar] = ($grouped[$ar] ?? 0.0) + $allocation['amount']; } foreach ($grouped as $accountId => $amount) { $lines[] = ['account_id' => (int) $accountId, 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => round((float) $amount, 2), 'line_order' => count($lines) + 1]; } $journal->lines()->createMany($lines); return $journal->refresh(); }

    private function validatedAllocations(SalesReceipt $receipt): array
    {
        $validator = app(BusinessReferenceValidator::class);
        $validator->customer((int) $receipt->customer_id);
        $cash = $validator->account((int) $receipt->cash_bank_account_id, ['asset']);
        if (! $cash->isCashBank()) throw ApiException::make('CASH_BANK_ACCOUNT_NOT_VALID', 'Cash/bank account must be active cash or bank account.', 422);

        $receipt->loadMissing('lines');
        $lines = $receipt->lines;
        if ($lines->isEmpty()) throw ApiException::make('PAYMENT_LINES_REQUIRED', 'Receipt lines are required.', 422);

        $total = round((float) $lines->sum('amount'), 2);
        if (abs($total - (float) $receipt->amount) > 0.0001) throw ApiException::make('PAYMENT_TOTAL_MISMATCH', 'Total line amount must match receipt amount.', 422);

        $allocations = [];
        foreach ($lines as $line) {
            $amount = (float) $line->amount;
            if ($amount <= 0) throw ApiException::make('PAYMENT_AMOUNT_INVALID', 'Receipt line amount must be greater than zero.', 422);
            $invoice = SalesInvoice::query()->lockForUpdate()->find($line->sales_invoice_id);
            if (! $invoice || ! in_array($invoice->status, ['posted', 'partially_paid'], true)) throw ApiException::make('SALES_INVOICE_NOT_PAYABLE', 'Sales invoice must be posted before payment.', 422);
            if ((int) $invoice->customer_id !== (int) $receipt->customer_id) throw ApiException::make('PAYMENT_CUSTOMER_MISMATCH', 'Receipt invoice customer must match receipt customer.', 422);
            if ($amount > (float) $invoice->balance_due) throw ApiException::make('OVERPAYMENT_NOT_ALLOWED', 'Payment amount exceeds invoice balance.', 422);
            $allocations[] = ['invoice' => $invoice, 'amount' => $amount];
        }

        return $allocations;
    }
}
