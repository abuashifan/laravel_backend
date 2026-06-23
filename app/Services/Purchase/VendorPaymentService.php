<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorPayment;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Purchase\Concerns\HandlesPurchaseDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Services\Audit\AuditLogService;
use App\Services\Validation\BusinessReferenceValidator;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class VendorPaymentService
{
    use HandlesPurchaseDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly PurchaseAccountResolverService $accountResolver,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly VendorDepositService $depositService,
        private readonly APSubsidiaryLedgerService $ledgerService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = VendorPayment::query()->with('vendor', 'vendorBill');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        return $query->orderByDesc('payment_date')->orderByDesc('id')->get();
    }

    public function find(int $id): VendorPayment
    {
        return VendorPayment::query()->with('lines.vendorBill', 'vendor', 'vendorBill', 'cashBankAccount')->findOrFail($id);
    }

    public function create(array $data): VendorPayment
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $this->ensureVendorExists((int) $data['vendor_id']);
        return DB::connection('tenant')->transaction(function () use ($company, $data) {
            $payment = VendorPayment::query()->create(array_merge($data, [
                'payment_number' => $this->documentNumberService->generate($company, DocumentType::VENDOR_PAYMENT, (string) $data['payment_date']),
                'status' => 'draft',
                'created_by' => auth()->id(),
            ]));
            $payment->lines()->createMany($data['lines'] ?? [[
                'vendor_bill_id' => $data['vendor_bill_id'] ?? null,
                'amount' => $data['amount'],
                'description' => $data['notes'] ?? null,
            ]]);
            $payment = $payment->refresh()->load('lines', 'vendor', 'vendorBill');
            return $this->shouldAutoPostOnCreateAccountingWorkflow() ? $this->post($payment) : $payment;
        });
    }

    public function post(VendorPayment $payment): VendorPayment
    {
        if ($payment->status === 'posted') throw ApiException::make('DOCUMENT_ALREADY_POSTED', 'Document has already been posted.', 422);
        $this->guardDate((string) $payment->payment_date);
        $allocations = $this->validatedAllocations($payment);

        return DB::connection('tenant')->transaction(function () use ($payment, $allocations) {
            $journal = $this->journal($payment, $allocations);
            $payment->status = 'posted';
            $payment->journal_entry_id = $journal->id;
            $payment->posted_by = auth()->id();
            $payment->posted_at = now();
            $payment->save();
            foreach ($allocations as $allocation) {
                $this->applyToBillAmount($allocation['bill'], $allocation['amount']);
            }
            return $payment->refresh()->load('lines', 'vendor', 'vendorBill');
        });
    }

    public function void(VendorPayment $payment, ?string $reason = null): VendorPayment
    {
        if ($payment->status === 'void') throw ApiException::make('VENDOR_PAYMENT_ALREADY_VOID', 'Vendor payment already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $payment->payment_date, 'void');
        return DB::connection('tenant')->transaction(function () use ($payment, $reason) {
            $journalIds = $this->voidEffectService->voidJournalsForSource('vendor_payment', (int) $payment->id, $reason);
            if ($payment->status === 'posted') {
                $payment->loadMissing('lines');
                foreach ($payment->lines as $line) {
                    if (! $line->vendor_bill_id) continue;
                    $bill = VendorBill::query()->lockForUpdate()->find($line->vendor_bill_id);
                    if ($bill && $bill->status !== 'void') {
                        $amount = (float) $line->amount;
                        $bill->paid_amount = max(0, (float) $bill->paid_amount - $amount);
                        $bill->balance_due = min((float) $bill->grand_total, (float) $bill->balance_due + $amount);
                        $bill->status = $bill->paid_amount > 0 ? 'partially_paid' : 'posted';
                        $bill->save();
                    }
                }
            }
            $payment->status = 'void'; $payment->voided_by = auth()->id(); $payment->voided_at = now(); $payment->void_reason = $reason; $payment->save();
            $this->auditPurchase($this->auditLogService, 'vendor_payment.voided', $payment, 'payment_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds]);
            return $payment->refresh();
        });
    }

    public function applyToBill(VendorPayment $payment, VendorBill $bill): void
    {
        $this->applyToBillAmount($bill, (float) $payment->amount);
    }

    public function applyToBillAmount(VendorBill $bill, float $amount): void
    {
        $bill->paid_amount = (float) $bill->paid_amount + $amount;
        $bill->balance_due = max(0, (float) $bill->balance_due - $amount);
        $bill->status = $bill->balance_due <= 0 ? 'paid' : 'partially_paid';
        $bill->save();
    }

    public function updateBillPaymentStatus(VendorBill $bill): VendorBill
    {
        $bill->status = (float) $bill->balance_due <= 0 ? 'paid' : ((float) $bill->paid_amount > 0 ? 'partially_paid' : $bill->status);
        $bill->save();
        return $bill->refresh();
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
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
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
            $grouped[$ap] = ($grouped[$ap] ?? 0.0) + $allocation['amount'];
        }
        foreach ($grouped as $accountId => $amount) {
            $lines[] = ['account_id' => (int) $accountId, 'description' => 'Accounts Payable', 'debit' => round((float) $amount, 2), 'credit' => 0, 'line_order' => count($lines) + 1];
        }
        $lines[] = ['account_id' => $payment->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => 0, 'credit' => $payment->amount, 'line_order' => count($lines) + 1];
        $journal->lines()->createMany($lines);
        return $journal->refresh();
    }

    private function mapping(string $key): int
    {
        $mapping = AccountMapping::query()->where('mapping_key', $key)->where('is_active', true)->first();
        if (! $mapping?->account_id) throw ApiException::make('ACCOUNT_MAPPING_MISSING', 'Required account mapping is missing: '.$key, 422);
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
        if (! $cash->isCashBank()) throw ApiException::make('CASH_BANK_ACCOUNT_NOT_VALID', 'Cash/bank account must be active cash or bank account.', 422);

        $payment->loadMissing('lines');
        $lines = $payment->lines;
        if ($lines->isEmpty()) throw ApiException::make('PAYMENT_LINES_REQUIRED', 'Payment lines are required.', 422);

        $total = round((float) $lines->sum('amount'), 2);
        if (abs($total - (float) $payment->amount) > 0.0001) throw ApiException::make('PAYMENT_TOTAL_MISMATCH', 'Total line amount must match payment amount.', 422);

        $allocations = [];
        foreach ($lines as $line) {
            $amount = (float) $line->amount;
            if ($amount <= 0) throw ApiException::make('PAYMENT_AMOUNT_INVALID', 'Payment line amount must be greater than zero.', 422);
            $bill = VendorBill::query()->lockForUpdate()->find($line->vendor_bill_id);
            if (! $bill || ! in_array($bill->status, ['posted', 'partially_paid'], true)) throw ApiException::make('VENDOR_BILL_NOT_PAYABLE', 'Vendor bill must be posted before payment.', 422);
            if ((int) $bill->vendor_id !== (int) $payment->vendor_id) throw ApiException::make('PAYMENT_VENDOR_MISMATCH', 'Payment bill vendor must match payment vendor.', 422);
            if ($amount > (float) $bill->balance_due) throw ApiException::make('OVERPAYMENT_NOT_ALLOWED', 'Payment amount exceeds bill balance.', 422);
            $allocations[] = ['bill' => $bill, 'amount' => $amount];
        }

        return $allocations;
    }
}
