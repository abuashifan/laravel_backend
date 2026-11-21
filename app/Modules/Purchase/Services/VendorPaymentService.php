<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Models\VendorPayment;
use App\Modules\Purchase\Services\Concerns\HandlesPurchaseDocuments;
use App\Shared\Api\AppliesListQuery;
use App\Shared\Audit\AuditLogService;
use App\Shared\CashBank\CashBankBalanceReader;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class VendorPaymentService
{
    use AppliesListQuery;
    use ChangesTransactionDocuments;
    use HandlesPurchaseDocuments;
    use TracksDocumentHistory;

    protected array $listSearchable = ['payment_number'];

    protected array $listSearchableRelations = ['vendor' => ['name', 'contact_code']];

    protected string $listDateColumn = 'payment_date';

    protected string $listStatusColumn = 'status';

    protected array $listDefaultSort = ['payment_date' => 'desc', 'id' => 'desc'];

    protected array $listSortable = ['payment_number', 'payment_date', 'status', 'created_at'];

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly PurchaseAccountResolverService $accountResolver,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly VendorDepositService $depositService,
        private readonly APSubsidiaryLedgerService $ledgerService,
        private readonly CashBankBalanceReader $cashBankReports,
        private readonly ?AuditLogService $auditLogService = null,
    ) {}

    /**
     * @param  array<string,mixed>  $filters
     * @return LengthAwarePaginator|Collection<int,VendorPayment>
     */
    public function list(array $filters = []): LengthAwarePaginator|Collection
    {
        $query = VendorPayment::query()->with('vendor', 'vendorBill');

        if (! empty($filters['vendor_id'])) {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        return $this->applyListQuery($query, $filters);
    }

    public function find(int $id): VendorPayment
    {
        return VendorPayment::query()->with('lines.vendorBill', 'lines.settlementDiscountAccount', 'vendor', 'vendorBill')->findOrFail($id);
    }

    public function create(array $data): VendorPayment
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }
        $this->ensureVendorExists((int) $data['vendor_id']);

        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $payment = VendorPayment::query()->create(array_merge(Arr::except($data, ['lines']), [
                'payment_number' => $this->documentNumberService->generate($company, DocumentType::VENDOR_PAYMENT, (string) $data['payment_date']),
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]));
            $payment->lines()->createMany($data['lines'] ?? [[
                'vendor_bill_id' => $data['vendor_bill_id'] ?? null,
                'amount' => $data['amount'],
                'description' => $data['notes'] ?? null,
            ]]);
            $payment = $payment->refresh()->load('lines.vendorBill', 'lines.settlementDiscountAccount', 'vendor', 'vendorBill');

            return $this->shouldAutoPostOnCreateAccountingWorkflow() ? $this->post($payment) : $payment;
        });
    }

    public function post(VendorPayment $payment): VendorPayment
    {
        return DB::connection('tenant')->transaction(function () use ($payment) {
            $payment = VendorPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status !== 'draft') {
                throw ApiException::make('DOCUMENT_ALREADY_POSTED', 'Hanya dokumen draf yang bisa diposting.', 422);
            }
            $this->guardDate((string) $payment->payment_date);
            $allocations = $this->validatedAllocations($payment);
            $journal = $this->journal($payment, $allocations);
            $payment->status = 'posted';
            $payment->journal_entry_id = $journal->id;
            $payment->posted_by = auth()->id();
            $payment->posted_at = now();
            $payment->save();
            foreach ($allocations as $allocation) {
                $this->applyToBillAmount($allocation['bill'], $allocation['amount'], $allocation['discount']);
            }

            return $payment->refresh()->load('lines.vendorBill', 'lines.settlementDiscountAccount', 'vendor', 'vendorBill');
        });
    }

    public function void(VendorPayment $payment, ?string $reason = null): VendorPayment
    {
        $reason = $this->voidEffectService->requireReason($reason);

        return DB::connection('tenant')->transaction(function () use ($payment, $reason) {
            $payment = VendorPayment::query()->lockForUpdate()->findOrFail($payment->id);
            if ($payment->status === 'void') {
                throw ApiException::make('VENDOR_PAYMENT_ALREADY_VOID', 'Vendor payment already void.', 422);
            }
            $this->guardDate((string) $payment->payment_date, 'void');
            $dependents = $this->dependents($payment);
            if ($dependents !== []) {
                throw ApiException::make('TRANSACTION_HAS_DEPENDENCY', 'Batalkan rekonsiliasi bank sebelum membatalkan pembayaran ini.', 422, ['dependents' => $dependents]);
            }
            $journalIds = $this->reversePostingEffects($payment, $reason);
            $payment->status = 'void';
            $payment->voided_by = auth()->id();
            $payment->voided_at = now();
            $payment->void_reason = $reason;
            $payment->save();
            $this->auditPurchase($this->auditLogService, 'vendor_payment.voided', $payment, 'payment_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds]);

            return $payment->refresh()->load('lines.vendorBill', 'lines.settlementDiscountAccount', 'vendor', 'vendorBill');
        });
    }

    /**
     * Edit pembayaran menurut mode lock/open. Draft diedit langsung; yang sudah
     * posted (mode open) dibatalkan dulu efeknya -- jurnal lama di-void, saldo
     * tagihan dikembalikan -- lalu diedit dan diposting ulang dengan nomor sama.
     */
    public function update(VendorPayment $payment, array $data): VendorPayment
    {
        return $this->withDocumentHistory($payment, fn (): VendorPayment => $this->applyUpdate($payment, $data));
    }

    private function applyUpdate(VendorPayment $payment, array $data): VendorPayment
    {
        return DB::connection('tenant')->transaction(function () use ($payment, $data) {
            $payment = VendorPayment::query()->lockForUpdate()->findOrFail($payment->id);
            $this->changePolicy()->assertDocumentChange(
                TransactionPolicyService::CHANGE_EDIT,
                TransactionModule::PURCHASE,
                (string) $payment->status,
                $this->dependents($payment),
                $payment->payment_date?->toDateString(),
            );
            $wasPosted = $payment->status === 'posted';
            if ($wasPosted) {
                $this->reopenToDraft($payment, 'Diedit setelah posting');
            }
            $payment = $this->updateDraft($payment->refresh(), $data);

            return $wasPosted ? $this->post($payment) : $payment;
        });
    }

    /**
     * Hapus pembayaran. Draft dihapus permanen; yang sudah posted di-void lalu
     * ditandai terhapus.
     */
    public function delete(VendorPayment $payment, ?string $reason = null): void
    {
        $this->changePolicy()->assertDocumentChange(
            TransactionPolicyService::CHANGE_DELETE,
            TransactionModule::PURCHASE,
            (string) $payment->status,
            $this->dependents($payment),
            $payment->payment_date?->toDateString(),
        );

        $reason = trim((string) $reason);

        DB::connection('tenant')->transaction(function () use ($payment, $reason): void {
            if ($payment->status === 'draft') {
                $this->auditPurchase($this->auditLogService, 'vendor_payment.deleted', $payment, 'payment_number', ['reason' => $reason, 'permanent' => true]);
                $payment->lines()->delete();
                $payment->forceDelete();

                return;
            }

            $voided = $this->void($payment, $this->deleteVoidReason($reason));
            $this->markVoidedDocumentDeleted($voided, $reason);
            $this->auditPurchase($this->auditLogService, 'vendor_payment.deleted', $voided, 'payment_number', ['reason' => $reason, 'permanent' => false]);
        });
    }

    /**
     * @return list<string>
     */
    public function dependents(VendorPayment $payment): array
    {
        return app(TransactionDependents::class)->reconciledSource('vendor_payment', (int) $payment->id);
    }

    private function updateDraft(VendorPayment $payment, array $data): VendorPayment
    {
        $this->ensureVendorExists((int) ($data['vendor_id'] ?? $payment->vendor_id));
        $header = $data;
        unset($header['lines'], $header['payment_number'], $header['status']);

        $payment->fill($header);
        $payment->save();
        $payment->lines()->delete();
        $payment->lines()->createMany($data['lines'] ?? [[
            'vendor_bill_id' => $payment->vendor_bill_id,
            'amount' => $payment->amount,
            'description' => $payment->notes,
        ]]);
        $payment = $payment->refresh()->load('lines.vendorBill', 'lines.settlementDiscountAccount', 'vendor', 'vendorBill');
        $this->auditPurchase($this->auditLogService, 'vendor_payment.updated', $payment, 'payment_number');

        return $payment;
    }

    /**
     * Batalkan efek posting pembayaran: jurnal di-void (tetap tersimpan) dan
     * jumlah terbayar tagihan dikembalikan. Dipakai void & edit-setelah-posting.
     *
     * @return array<int>
     */
    private function reversePostingEffects(VendorPayment $payment, string $reason): array
    {
        $journalIds = $this->voidEffectService->voidJournalsForSource('vendor_payment', (int) $payment->id, $reason);
        if ($payment->status === 'posted') {
            $payment->loadMissing('lines');
            foreach ($payment->lines->sortBy('vendor_bill_id') as $line) {
                if (! $line->vendor_bill_id) {
                    continue;
                }
                $bill = VendorBill::query()->lockForUpdate()->find($line->vendor_bill_id);
                if ($bill && $bill->status !== 'void') {
                    $amount = (float) $line->amount;
                    $discount = (float) $line->settlement_discount_amount;
                    $bill->paid_amount = max(0, round((float) $bill->paid_amount - $amount, 2));
                    $bill->settlement_discount_amount = max(0, round((float) $bill->settlement_discount_amount - $discount, 2));
                    $maximumDue = round((float) $bill->grand_total - (float) $bill->returned_amount
                        - (float) $bill->paid_amount - (float) $bill->settlement_discount_amount, 2);
                    $bill->balance_due = max(0, min($maximumDue, round((float) $bill->balance_due + $amount + $discount, 2)));
                    if ((float) $bill->balance_due === 0.0) {
                        $bill->status = 'paid';
                    } else {
                        $bill->status = $bill->paid_amount > 0 ? 'partially_paid' : 'posted';
                    }
                    $bill->save();
                }
            }
        }

        return $journalIds;
    }

    private function reopenToDraft(VendorPayment $payment, string $reason): void
    {
        $this->guardDate((string) $payment->payment_date, 'void');
        $this->reversePostingEffects($payment, $reason);
        $payment->status = 'draft';
        $payment->journal_entry_id = null;
        $payment->posted_by = null;
        $payment->posted_at = null;
        $payment->save();
        $this->auditPurchase($this->auditLogService, 'vendor_payment.reopened', $payment, 'payment_number', ['reason' => $reason]);
    }

    public function applyToBill(VendorPayment $payment, VendorBill $bill): void
    {
        $this->applyToBillAmount($bill, (float) $payment->amount);
    }

    public function applyToBillAmount(VendorBill $bill, float $amount, float $discount = 0.0): void
    {
        $bill->paid_amount = round((float) $bill->paid_amount + $amount, 2);
        $bill->settlement_discount_amount = round((float) $bill->settlement_discount_amount + $discount, 2);
        $bill->balance_due = max(0, round((float) $bill->balance_due - $amount - $discount, 2));
        $bill->status = $bill->balance_due <= 0 ? 'paid' : 'partially_paid';
        $bill->save();
    }

    public function updateBillPaymentStatus(VendorBill $bill): VendorBill
    {
        $bill->status = (float) $bill->balance_due <= 0 ? 'paid' : ((float) $bill->paid_amount > 0 ? 'partially_paid' : $bill->status);
        $bill->save();

        return $bill->refresh();
    }

    public function cashBalance(int $accountId, string $date, ?int $paymentId = null): array
    {
        $payment = $paymentId ? VendorPayment::query()->findOrFail($paymentId) : null;
        $journalId = $payment?->status === 'posted' ? $payment->journal_entry_id : null;

        return $this->cashBankReports->accountBalance($accountId, $date, $journalId === null ? null : (int) $journalId);
    }

    public function vendorContext(int $vendorId): array
    {
        $openBills = $this->ledgerService->openBills(['vendor_id' => $vendorId]);
        $available = $this->depositService->availableForVendor($vendorId);
        $officialApBalance = round((float) collect($openBills)->sum('balance_due'), 2);
        $unappliedDeposit = (float) $available['unapplied_total'];

        return [
            'vendor_id' => $vendorId,
            'gross_ap_outstanding' => $officialApBalance,
            'official_ap_balance' => $officialApBalance,
            'unapplied_deposit_total' => $unappliedDeposit,
            'net_vendor_exposure' => round($officialApBalance - $unappliedDeposit, 2),
            'open_bills' => $openBills,
            'available_deposits' => $available['deposits'],
        ];
    }

    private function journal(VendorPayment $payment, array $allocations): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }
        $journal = JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $payment->payment_date),
            'journal_date' => $payment->payment_date,
            'description' => 'Vendor payment '.$payment->payment_number,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => 'vendor_payment',
            'source_id' => $payment->id,
            'source_number' => $payment->payment_number,
            'source_revision' => 1,
            'source_module' => 'purchase',
            'is_system_generated' => true,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);
        $lines = [];
        $grouped = [];
        foreach ($allocations as $allocation) {
            $ap = $this->accountResolver->resolveBillPayableAccountId($allocation['bill']);
            $grouped[$ap] = ($grouped[$ap] ?? 0.0) + $allocation['amount'] + $allocation['discount'];
        }
        foreach ($grouped as $accountId => $amount) {
            $lines[] = ['account_id' => (int) $accountId, 'description' => 'Accounts Payable', 'debit' => round((float) $amount, 2), 'credit' => 0, 'line_order' => count($lines) + 1];
        }
        $lines[] = ['account_id' => $payment->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => 0, 'credit' => $payment->amount, 'line_order' => count($lines) + 1];

        foreach ($allocations as $allocation) {
            foreach ($allocation['discount_lines'] as $line) {
                $description = 'Potongan pelunasan '.$allocation['bill']->bill_number;
                if ($line['note'] !== null) {
                    $description .= ' — '.$line['note'];
                }
                $lines[] = [
                    'account_id' => $line['account_id'],
                    'description' => mb_substr($description, 0, 255),
                    'debit' => 0, 'credit' => $line['amount'],
                    'line_order' => count($lines) + 1,
                ];
            }
        }
        $journal->lines()->createMany($lines);

        return $journal->refresh();
    }

    private function mapping(string $key): int
    {
        $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first();
        if (! $mapping?->account_id) {
            throw ApiException::make('ACCOUNT_MAPPING_MISSING', 'Required account mapping is missing: '.$key, 422);
        }

        return (int) $mapping->account_id;
    }

    private function guardDate(string $date, string $action = 'post'): void
    {
        $check = $this->dateGuardService->check($date, $action, 'purchase');
        if ($check->denied()) {
            $arr = $check->toArray();
            throw ApiException::make((string) $arr['code'], (string) $arr['message'], 422, (array) $arr['reasons'], (array) $arr['meta']);
        }
    }

    private function validatedAllocations(VendorPayment $payment): array
    {
        $validator = app(BusinessReferenceValidator::class);
        $validator->vendor((int) $payment->vendor_id);
        $cash = $validator->account((int) $payment->cash_bank_account_id, ['asset']);
        if (! $cash->isCashBank()) {
            throw ApiException::make('CASH_BANK_ACCOUNT_NOT_VALID', 'Cash/bank account must be active cash or bank account.', 422);
        }

        $payment->loadMissing('lines');
        $lines = $payment->lines;
        if ($lines->isEmpty()) {
            throw ApiException::make('PAYMENT_LINES_REQUIRED', 'Payment lines are required.', 422);
        }

        $total = round((float) $lines->sum('amount'), 2);
        if (abs($total - (float) $payment->amount) > 0.0001) {
            throw ApiException::make('PAYMENT_TOTAL_MISMATCH', 'Total line amount must match payment amount.', 422);
        }

        foreach ($lines as $line) {
            if ((float) $line->amount <= 0 || (float) $line->settlement_discount_amount < 0) {
                throw ApiException::make('PAYMENT_AMOUNT_INVALID', 'Jumlah kas per baris wajib positif dan potongan tidak boleh negatif.', 422);
            }
        }

        $allocations = [];
        foreach ($lines->groupBy('vendor_bill_id')->sortKeys() as $id => $invoiceLines) {
            $amount = round((float) $invoiceLines->sum('amount'), 2);
            $discount = round((float) $invoiceLines->sum('settlement_discount_amount'), 2);
            $bill = VendorBill::query()->lockForUpdate()->find($id);
            if (! $bill || ! in_array($bill->status, ['posted', 'partially_paid'], true)) {
                throw ApiException::make('VENDOR_BILL_NOT_PAYABLE', 'Vendor bill must be posted before payment.', 422);
            }
            if ((int) $bill->vendor_id !== (int) $payment->vendor_id) {
                throw ApiException::make('PAYMENT_VENDOR_MISMATCH', 'Payment bill vendor must match payment vendor.', 422);
            }
            if (round($amount + $discount, 2) > (float) $bill->balance_due) {
                throw ApiException::make('OVERPAYMENT_NOT_ALLOWED', 'Jumlah kas dan potongan melebihi sisa tagihan.', 422);
            }
            $discountLines = [];
            foreach ($invoiceLines as $line) {
                if ((float) $line->settlement_discount_amount <= 0) {
                    continue;
                }
                if (! $line->settlement_discount_account_id) {
                    throw ApiException::make('SETTLEMENT_DISCOUNT_ACCOUNT_REQUIRED', 'Pilih akun untuk potongan pelunasan.', 422);
                }
                $account = $validator->account((int) $line->settlement_discount_account_id, ['expense', 'revenue']);
                $discountLines[] = [
                    'account_id' => (int) $account->id,
                    'amount' => (float) $line->settlement_discount_amount,
                    'note' => $line->settlement_discount_note,
                ];
            }
            $allocations[] = [
                'bill' => $bill, 'amount' => $amount, 'discount' => $discount, 'discount_lines' => $discountLines,
            ];
        }

        return $allocations;
    }
}
