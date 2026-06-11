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
            $receipt->lines()->createMany($data['lines'] ?? [['sales_invoice_id' => $data['sales_invoice_id'] ?? null, 'billing_invoice_id' => $data['billing_invoice_id'] ?? null, 'amount' => $data['amount'], 'description' => $data['notes'] ?? null]]);
            return $receipt->refresh()->load('lines', 'customer', 'salesInvoice');
        });
    }

    public function post(SalesReceipt $receipt): SalesReceipt
    {
        if ($receipt->status === 'posted') return $receipt;
        $this->guardDate((string) $receipt->receipt_date);
        $invoice = $receipt->sales_invoice_id ? SalesInvoice::query()->findOrFail($receipt->sales_invoice_id) : null;
        if (! $invoice || ! in_array($invoice->status, ['posted', 'partially_paid'], true)) throw ApiException::make('SALES_INVOICE_NOT_PAYABLE', 'Sales invoice must be posted before payment.', 422);
        if ((float) $receipt->amount > (float) $invoice->balance_due) throw ApiException::make('OVERPAYMENT_NOT_ALLOWED', 'Overpayment is blocked for MVP.', 422);
        return DB::connection('tenant')->transaction(function () use ($receipt, $invoice) {
            $journal = $this->journal($receipt, $invoice);
            $receipt->status = 'posted'; $receipt->journal_entry_id = $journal->id; $receipt->posted_by = auth()->id(); $receipt->posted_at = now(); $receipt->save();
            $this->applyToInvoice($receipt, $invoice);
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
            if ($receipt->status === 'posted' && $receipt->sales_invoice_id) {
                $invoice = SalesInvoice::query()->lockForUpdate()->find($receipt->sales_invoice_id);
                if ($invoice && $invoice->status !== 'void') {
                    $invoice->paid_amount = max(0, (float) $invoice->paid_amount - (float) $receipt->amount);
                    $invoice->balance_due = min((float) $invoice->grand_total, (float) $invoice->balance_due + (float) $receipt->amount);
                    $invoice->status = $invoice->paid_amount > 0 ? 'partially_paid' : 'posted';
                    $invoice->save();
                }
            }
            $receipt->status = 'void'; $receipt->voided_by = auth()->id(); $receipt->voided_at = now(); $receipt->void_reason = $reason; $receipt->save();
            $this->auditSales($this->auditLogService, 'sales_receipt.voided', 'sales', $receipt, 'receipt_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds]);
            return $receipt->refresh();
        });
    }

    public function applyToInvoice(SalesReceipt $receipt, SalesInvoice $invoice): void
    {
        $invoice->paid_amount = (float) $invoice->paid_amount + (float) $receipt->amount;
        $invoice->balance_due = max(0, (float) $invoice->balance_due - (float) $receipt->amount);
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
    private function journal(SalesReceipt $receipt, SalesInvoice $invoice): JournalEntry { $company = $this->tenantContext->company(); if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422); $ar = $this->accountResolver->resolveInvoiceReceivableAccountId($invoice); $journal = JournalEntry::query()->create(['journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $receipt->receipt_date), 'journal_date' => $receipt->receipt_date, 'description' => 'Sales receipt '.$receipt->receipt_number, 'status' => 'posted', 'revision_no' => 1, 'source_type' => 'sales_receipt', 'source_id' => $receipt->id, 'source_number' => $receipt->receipt_number, 'source_revision' => 1, 'source_module' => 'sales', 'is_system_generated' => true, 'created_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now()]); $journal->lines()->createMany([['account_id' => $receipt->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => $receipt->amount, 'credit' => 0, 'line_order' => 1], ['account_id' => $ar, 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => $receipt->amount, 'line_order' => 2]]); return $journal->refresh(); }
}
