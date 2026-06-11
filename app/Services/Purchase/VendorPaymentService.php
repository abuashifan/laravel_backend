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
        return VendorPayment::query()->with('lines', 'vendor', 'vendorBill')->findOrFail($id);
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
            return $payment->refresh()->load('lines', 'vendor', 'vendorBill');
        });
    }

    public function post(VendorPayment $payment): VendorPayment
    {
        if ($payment->status === 'posted') return $payment;
        $this->guardDate((string) $payment->payment_date);
        $bill = $payment->vendor_bill_id ? VendorBill::query()->findOrFail($payment->vendor_bill_id) : null;
        if (! $bill || ! in_array($bill->status, ['posted', 'partially_paid'], true)) throw ApiException::make('VENDOR_BILL_NOT_PAYABLE', 'Vendor bill must be posted before payment.', 422);
        if ((float) $payment->amount > (float) $bill->balance_due) throw ApiException::make('OVERPAYMENT_NOT_ALLOWED', 'Overpayment is blocked for MVP.', 422);

        return DB::connection('tenant')->transaction(function () use ($payment, $bill) {
            $journal = $this->journal($payment, $bill);
            $payment->status = 'posted';
            $payment->journal_entry_id = $journal->id;
            $payment->posted_by = auth()->id();
            $payment->posted_at = now();
            $payment->save();
            $this->applyToBill($payment, $bill);
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
            if ($payment->status === 'posted' && $payment->vendor_bill_id) {
                $bill = VendorBill::query()->lockForUpdate()->find($payment->vendor_bill_id);
                if ($bill && $bill->status !== 'void') {
                    $bill->paid_amount = max(0, (float) $bill->paid_amount - (float) $payment->amount);
                    $bill->balance_due = min((float) $bill->grand_total, (float) $bill->balance_due + (float) $payment->amount);
                    $bill->status = $bill->paid_amount > 0 ? 'partially_paid' : 'posted';
                    $bill->save();
                }
            }
            $payment->status = 'void'; $payment->voided_by = auth()->id(); $payment->voided_at = now(); $payment->void_reason = $reason; $payment->save();
            $this->auditPurchase($this->auditLogService, 'vendor_payment.voided', $payment, 'payment_number', ['reason' => $reason, 'voided_journal_ids' => $journalIds]);
            return $payment->refresh();
        });
    }

    public function applyToBill(VendorPayment $payment, VendorBill $bill): void
    {
        $bill->paid_amount = (float) $bill->paid_amount + (float) $payment->amount;
        $bill->balance_due = max(0, (float) $bill->balance_due - (float) $payment->amount);
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

    private function journal(VendorPayment $payment, VendorBill $bill): JournalEntry
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
        $journal->lines()->createMany([
            ['account_id' => $this->accountResolver->resolveBillPayableAccountId($bill), 'description' => 'Accounts Payable', 'debit' => $payment->amount, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $payment->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => 0, 'credit' => $payment->amount, 'line_order' => 2],
        ]);
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
}
