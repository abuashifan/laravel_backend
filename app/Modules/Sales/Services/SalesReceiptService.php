<?php

namespace App\Modules\Sales\Services;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReceipt;
use App\Modules\Sales\Services\Concerns\HandlesSalesDocuments;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Audit\AuditLogService;
use App\Shared\DocumentNumbering\DocumentNumberService;
use App\Shared\DocumentNumbering\DocumentType;
use App\Shared\Exceptions\ApiException;
use App\Shared\Tenant\TenantContext;
use App\Shared\TransactionLifecycle\ChangesTransactionDocuments;
use App\Shared\TransactionLifecycle\TracksDocumentHistory;
use App\Shared\TransactionLifecycle\TransactionDateGuardService;
use App\Shared\TransactionLifecycle\TransactionDependents;
use App\Shared\TransactionLifecycle\TransactionModule;
use App\Shared\TransactionLifecycle\TransactionPolicyService;
use App\Shared\TransactionLifecycle\TransactionVoidEffectService;
use App\Shared\Validation\BusinessReferenceValidator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class SalesReceiptService
{
    use AppliesListQuery;
    use ChangesTransactionDocuments;
    use HandlesSalesDocuments;
    use TracksDocumentHistory;

    protected array $listSearchable = ['receipt_number'];

    protected array $listSearchableRelations = ['customer' => ['name', 'contact_code']];

    protected string $listDateColumn = 'receipt_date';

    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['receipt_date' => 'desc', 'id' => 'desc'];

    protected array $listSortable = ['receipt_number', 'receipt_date', 'status', 'created_at'];

    public function __construct(private readonly TenantContext $tenantContext, private readonly DocumentNumberService $documentNumberService, private readonly TransactionDateGuardService $dateGuardService, private readonly TransactionVoidEffectService $voidEffectService, private readonly SalesAccountResolverService $accountResolver, private readonly CustomerDepositService $depositService, private readonly ARSubsidiaryLedgerService $ledgerService, private readonly ?AuditLogService $auditLogService = null) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,SalesReceipt>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = SalesReceipt::query()->with('customer', 'salesInvoice', 'cashBankAccount');

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        return $this->applyListQuery($query, $filters);
    }

    public function find(int $id): SalesReceipt
    {
        return SalesReceipt::query()->with('lines', 'customer', 'salesInvoice', 'cashBankAccount')->findOrFail($id);
    }

    public function create(array $data): SalesReceipt
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }
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
        if ($receipt->status === 'posted') {
            throw ApiException::make('DOCUMENT_ALREADY_POSTED', 'Document has already been posted.', 422);
        }
        $this->guardDate((string) $receipt->receipt_date);
        $allocations = $this->validatedAllocations($receipt);

        return DB::connection('tenant')->transaction(function () use ($receipt, $allocations) {
            $journal = $this->journal($receipt, $allocations);
            $receipt->status = 'posted';
            $receipt->journal_entry_id = $journal->id;
            $receipt->posted_by = auth()->id();
            $receipt->posted_at = now();
            $receipt->save();
            foreach ($allocations as $allocation) {
                $this->applyToInvoiceAmount($allocation['invoice'], $allocation['amount']);
            }

            return $receipt->refresh()->load('lines', 'customer', 'salesInvoice');
        });
    }

    public function void(SalesReceipt $receipt, ?string $reason = null): SalesReceipt
    {
        if ($receipt->status === 'void') {
            throw ApiException::make('SALES_RECEIPT_ALREADY_VOID', 'Sales receipt already void.', 422);
        }
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $receipt->receipt_date, 'void');

        return DB::connection('tenant')->transaction(function () use ($receipt, $reason) {
            $journalIds = $this->reversePostingEffects($receipt, $reason);
            $receipt->status = 'void';
            $receipt->voided_by = auth()->id();
            $receipt->voided_at = now();
            $receipt->void_reason = $reason;
            $receipt->save();
            $this->auditSales($this->auditLogService, 'sales_receipt.voided', 'sales', $receipt, 'receipt_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds]);

            return $receipt->refresh();
        });
    }

    /**
     * Edit penerimaan menurut mode lock/open. Draft diedit langsung; yang sudah
     * posted (mode open) dibatalkan dulu efeknya -- jurnal lama di-void, saldo
     * faktur dikembalikan -- lalu diedit dan diposting ulang dengan nomor sama.
     */
    public function update(SalesReceipt $receipt, array $data): SalesReceipt
    {
        return $this->withDocumentHistory($receipt, fn (): SalesReceipt => $this->applyUpdate($receipt, $data));
    }

    private function applyUpdate(SalesReceipt $receipt, array $data): SalesReceipt
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_EDIT,
            TransactionModule::SALES,
            (string) $receipt->status,
            $this->dependents($receipt),
            $receipt->receipt_date?->toDateString(),
        );

        return DB::connection('tenant')->transaction(function () use ($receipt, $data) {
            $wasPosted = $receipt->status === 'posted';
            if ($wasPosted) {
                $this->reopenToDraft($receipt, 'Diedit setelah posting');
            }
            $receipt = $this->updateDraft($receipt->refresh(), $data);

            return $wasPosted ? $this->post($receipt) : $receipt;
        });
    }

    /**
     * Hapus penerimaan. Draft dihapus permanen; yang sudah posted di-void lalu
     * ditandai terhapus.
     */
    public function delete(SalesReceipt $receipt, ?string $reason = null): void
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_DELETE,
            TransactionModule::SALES,
            (string) $receipt->status,
            $this->dependents($receipt),
            $receipt->receipt_date?->toDateString(),
        );

        $reason = trim((string) $reason);

        DB::connection('tenant')->transaction(function () use ($receipt, $reason): void {
            if ($receipt->status === 'draft') {
                $this->auditSales($this->auditLogService, 'sales_receipt.deleted', 'sales', $receipt, 'receipt_number', ['reason' => $reason, 'permanent' => true]);
                $receipt->lines()->delete();
                $receipt->forceDelete();

                return;
            }

            $voided = $this->void($receipt, $this->deleteVoidReason($reason));
            $this->markVoidedDocumentDeleted($voided, $reason);
            $this->auditSales($this->auditLogService, 'sales_receipt.deleted', 'sales', $voided, 'receipt_number', ['reason' => $reason, 'permanent' => false]);
        });
    }

    /**
     * @return list<string>
     */
    public function dependents(SalesReceipt $receipt): array
    {
        return app(TransactionDependents::class)->reconciledSource('sales_receipt', (int) $receipt->id);
    }

    private function updateDraft(SalesReceipt $receipt, array $data): SalesReceipt
    {
        $this->ensureCustomerExists((int) ($data['customer_id'] ?? $receipt->customer_id));
        $header = $data;
        unset($header['lines'], $header['receipt_number'], $header['status']);

        $receipt->fill($header);
        $receipt->save();
        $receipt->lines()->delete();
        $receipt->lines()->createMany($data['lines'] ?? [['sales_invoice_id' => $receipt->sales_invoice_id, 'amount' => $receipt->amount, 'description' => $receipt->notes]]);
        $receipt = $receipt->refresh()->load('lines', 'customer', 'salesInvoice');
        $this->auditSales($this->auditLogService, 'sales_receipt.updated', 'sales', $receipt, 'receipt_number');

        return $receipt;
    }

    /**
     * Batalkan efek posting penerimaan: jurnal di-void (tetap tersimpan) dan
     * jumlah terbayar faktur dikembalikan. Dipakai void & edit-setelah-posting.
     *
     * @return array<int>
     */
    private function reversePostingEffects(SalesReceipt $receipt, string $reason): array
    {
        $journalIds = $this->voidEffectService->voidJournalsForSource('sales_receipt', (int) $receipt->id, $reason);
        if ($receipt->status === 'posted') {
            $receipt->loadMissing('lines');
            foreach ($receipt->lines as $line) {
                if (! $line->sales_invoice_id) {
                    continue;
                }
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

        return $journalIds;
    }

    private function reopenToDraft(SalesReceipt $receipt, string $reason): void
    {
        $this->guardDate((string) $receipt->receipt_date, 'void');
        $this->reversePostingEffects($receipt, $reason);
        $receipt->status = 'draft';
        $receipt->journal_entry_id = null;
        $receipt->posted_by = null;
        $receipt->posted_at = null;
        $receipt->save();
        $this->auditSales($this->auditLogService, 'sales_receipt.reopened', 'sales', $receipt, 'receipt_number', ['reason' => $reason]);
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

    public function updateInvoicePaymentStatus(SalesInvoice $invoice): SalesInvoice
    {
        $invoice->status = (float) $invoice->balance_due <= 0 ? 'paid' : ((float) $invoice->paid_amount > 0 ? 'partially_paid' : $invoice->status);
        $invoice->save();

        return $invoice->refresh();
    }

    private function guardDate(string $date, string $action = 'post'): void
    {
        $check = $this->dateGuardService->check($date, $action, 'sales');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    private function journal(SalesReceipt $receipt, array $allocations): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        } $journal = JournalEntry::query()->create(['journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $receipt->receipt_date), 'journal_date' => $receipt->receipt_date, 'description' => 'Sales receipt '.$receipt->receipt_number, 'status' => 'posted', 'revision_no' => 1, 'source_type' => 'sales_receipt', 'source_id' => $receipt->id, 'source_number' => $receipt->receipt_number, 'source_revision' => 1, 'source_module' => 'sales', 'is_system_generated' => true, 'created_by' => auth()->id(), 'posted_by' => auth()->id(), 'posted_at' => now()]);
        $lines = [['account_id' => $receipt->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => $receipt->amount, 'credit' => 0, 'line_order' => 1]];
        $grouped = [];
        foreach ($allocations as $allocation) {
            $ar = $this->accountResolver->resolveInvoiceReceivableAccountId($allocation['invoice']);
            $grouped[$ar] = ($grouped[$ar] ?? 0.0) + $allocation['amount'];
        } foreach ($grouped as $accountId => $amount) {
            $lines[] = ['account_id' => (int) $accountId, 'description' => 'Accounts Receivable', 'debit' => 0, 'credit' => round((float) $amount, 2), 'line_order' => count($lines) + 1];
        } $journal->lines()->createMany($lines);

        return $journal->refresh();
    }

    private function validatedAllocations(SalesReceipt $receipt): array
    {
        $validator = app(BusinessReferenceValidator::class);
        $validator->customer((int) $receipt->customer_id);
        $cash = $validator->account((int) $receipt->cash_bank_account_id, ['asset']);
        if (! $cash->isCashBank()) {
            throw ApiException::make('CASH_BANK_ACCOUNT_NOT_VALID', 'Cash/bank account must be active cash or bank account.', 422);
        }

        $receipt->loadMissing('lines');
        $lines = $receipt->lines;
        if ($lines->isEmpty()) {
            throw ApiException::make('PAYMENT_LINES_REQUIRED', 'Receipt lines are required.', 422);
        }

        $total = round((float) $lines->sum('amount'), 2);
        if (abs($total - (float) $receipt->amount) > 0.0001) {
            throw ApiException::make('PAYMENT_TOTAL_MISMATCH', 'Total line amount must match receipt amount.', 422);
        }

        $allocations = [];
        foreach ($lines as $line) {
            $amount = (float) $line->amount;
            if ($amount <= 0) {
                throw ApiException::make('PAYMENT_AMOUNT_INVALID', 'Receipt line amount must be greater than zero.', 422);
            }
            $invoice = SalesInvoice::query()->lockForUpdate()->find($line->sales_invoice_id);
            if (! $invoice || ! in_array($invoice->status, ['posted', 'partially_paid'], true)) {
                throw ApiException::make('SALES_INVOICE_NOT_PAYABLE', 'Sales invoice must be posted before payment.', 422);
            }
            if ((int) $invoice->customer_id !== (int) $receipt->customer_id) {
                throw ApiException::make('PAYMENT_CUSTOMER_MISMATCH', 'Receipt invoice customer must match receipt customer.', 422);
            }
            if ($amount > (float) $invoice->balance_due) {
                throw ApiException::make('OVERPAYMENT_NOT_ALLOWED', 'Payment amount exceeds invoice balance.', 422);
            }
            $allocations[] = ['invoice' => $invoice, 'amount' => $amount];
        }

        return $allocations;
    }
}
