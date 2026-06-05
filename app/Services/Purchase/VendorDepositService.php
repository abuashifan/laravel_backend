<?php

namespace App\Services\Purchase;

use App\Exceptions\ApiException;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\PurchaseOrder;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorDeposit;
use App\Models\Tenant\VendorDepositAllocation;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Services\Purchase\Concerns\HandlesPurchaseDocuments;
use App\Services\Tenant\TenantContext;
use App\Services\Transactions\TransactionDateGuardService;
use App\Services\Transactions\TransactionVoidEffectService;
use App\Services\Audit\AuditLogService;
use App\Support\DocumentNumbering\DocumentType;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class VendorDepositService
{
    use HandlesPurchaseDocuments;

    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly DocumentNumberService $documentNumberService,
        private readonly TransactionDateGuardService $dateGuardService,
        private readonly TransactionVoidEffectService $voidEffectService,
        private readonly ?AuditLogService $auditLogService = null,
    ) {
    }

    public function list(array $filters = []): Collection
    {
        $query = VendorDeposit::query()->with('vendor', 'purchaseOrder');
        if (! empty($filters['status'])) $query->where('status', (string) $filters['status']);
        if (! empty($filters['vendor_id'])) $query->where('vendor_id', (int) $filters['vendor_id']);
        return $query->orderByDesc('deposit_date')->orderByDesc('id')->get();
    }

    public function find(int $id): VendorDeposit
    {
        return VendorDeposit::query()->with('vendor', 'purchaseOrder')->findOrFail($id);
    }

    public function create(array $data): VendorDeposit
    {
        $company = $this->tenantContext->company();
        if (! $company) {
            throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        }

        $amount = (float) $data['amount'];
        $this->ensureVendorExists((int) $data['vendor_id']);

        return VendorDeposit::query()->create(array_merge($data, [
            'deposit_number' => $this->documentNumberService->generate($company, DocumentType::VENDOR_DEPOSIT, (string) $data['deposit_date']),
            'remaining_amount' => $amount,
            'allocated_amount' => 0,
            'status' => 'draft',
            'created_by' => auth()->id(),
        ]))->refresh();
    }

    public function createFromPurchaseOrder(PurchaseOrder $order, array $depositData): VendorDeposit
    {
        return $this->create(array_merge($depositData, [
            'vendor_id' => $order->vendor_id,
            'purchase_order_id' => $order->id,
            'currency_code' => $order->currency_code,
            'exchange_rate' => $order->exchange_rate,
            'source_type' => 'purchase_order',
            'source_id' => $order->id,
            'source_number' => $order->order_number,
            'source_revision' => $order->revision_no,
        ]));
    }

    public function calculateReceivedForPurchaseOrder(PurchaseOrder $order): float
    {
        return (float) $order->deposits()->where('status', '!=', 'void')->sum('amount');
    }

    public function post(VendorDeposit $deposit): VendorDeposit
    {
        if ($deposit->status === 'posted') return $deposit;
        $this->guardDate((string) $deposit->deposit_date);

        return DB::connection('tenant')->transaction(function () use ($deposit) {
            $journal = $this->journal($deposit, 'Vendor deposit '.$deposit->deposit_number, [
                ['account_id' => $this->mapping('purchase.vendor_deposit'), 'description' => 'Vendor Deposit', 'debit' => $deposit->amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $deposit->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => 0, 'credit' => $deposit->amount, 'line_order' => 2],
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

    public function void(VendorDeposit $deposit, ?string $reason = null): VendorDeposit
    {
        if ($deposit->status === 'void') throw ApiException::make('VENDOR_DEPOSIT_ALREADY_VOID', 'Vendor deposit already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $deposit->deposit_date, 'void');
        return DB::connection('tenant')->transaction(function () use ($deposit, $reason) {
            $journalIds = $this->voidEffectService->voidJournalsForSource('vendor_deposit', (int) $deposit->id, $reason);
            $allocations = VendorDepositAllocation::query()->where('vendor_deposit_id', $deposit->id)->where('status', 'posted')->get();
            foreach ($allocations as $allocation) {
                $bill = VendorBill::query()->lockForUpdate()->find($allocation->vendor_bill_id);
                if ($bill && $bill->status !== 'void') {
                    $bill->paid_amount = max(0, (float) $bill->paid_amount - (float) $allocation->allocated_amount);
                    $bill->applied_vendor_deposit_amount = max(0, (float) $bill->applied_vendor_deposit_amount - (float) $allocation->allocated_amount);
                    $bill->balance_due = min((float) $bill->grand_total, (float) $bill->balance_due + (float) $allocation->allocated_amount);
                    $bill->status = $bill->paid_amount > 0 ? 'partially_paid' : 'posted';
                    $bill->save();
                }
                $journalId = $this->voidEffectService->voidJournalById((int) $allocation->journal_entry_id, $reason);
                if ($journalId) $journalIds[] = $journalId;
                $allocation->status = 'void'; $allocation->voided_by = auth()->id(); $allocation->voided_at = now(); $allocation->void_reason = $reason; $allocation->save();
            }
            $deposit->status = 'void'; $deposit->voided_by = auth()->id(); $deposit->voided_at = now(); $deposit->void_reason = $reason; $deposit->save();
            $this->auditPurchase($this->auditLogService, 'vendor_deposit.voided', $deposit, 'deposit_number', ['reason' => $reason, 'voided_journal_ids' => array_values(array_unique($journalIds)), 'voided_allocation_ids' => $allocations->pluck('id')->all()]);
            return $deposit->refresh();
        });
    }

    public function refund(VendorDeposit $deposit, float $amount, ?string $reason = null): VendorDeposit
    {
        if ($amount > (float) $deposit->remaining_amount) throw ApiException::make('REFUND_EXCEEDS_REMAINING_DEPOSIT', 'Refund exceeds remaining deposit.', 422);
        $this->guardDate((string) $deposit->deposit_date);

        return DB::connection('tenant')->transaction(function () use ($deposit, $amount, $reason) {
            $journal = $this->journal($deposit, 'Refund vendor deposit '.$deposit->deposit_number, [
                ['account_id' => $deposit->cash_bank_account_id, 'description' => 'Cash/Bank', 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $this->mapping('purchase.vendor_deposit'), 'description' => 'Vendor Deposit', 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ]);
            $deposit->remaining_amount = (float) $deposit->remaining_amount - $amount;
            $deposit->refund_journal_entry_id = $journal->id;
            $deposit->status = $deposit->remaining_amount <= 0 ? 'refunded' : 'partially_allocated';
            $deposit->refunded_by = auth()->id();
            $deposit->refunded_at = now();
            $deposit->refund_reason = $reason;
            $deposit->save();
            return $deposit->refresh();
        });
    }

    public function allocateToBill(VendorDeposit $deposit, VendorBill $bill, float $amount): VendorDepositAllocation
    {
        if ($deposit->vendor_id !== $bill->vendor_id) throw ApiException::make('VENDOR_MISMATCH', 'Deposit and bill vendor mismatch.', 422);
        if ($amount > (float) $deposit->remaining_amount) throw ApiException::make('VENDOR_DEPOSIT_INSUFFICIENT', 'Cannot allocate more than remaining deposit.', 422);
        if ($amount > (float) $bill->balance_due) throw ApiException::make('VENDOR_DEPOSIT_ALLOCATION_EXCEEDS_BILL', 'Allocation exceeds bill balance.', 422);
        $this->guardDate((string) $bill->bill_date);

        return DB::connection('tenant')->transaction(function () use ($deposit, $bill, $amount) {
            $journal = $this->journal($deposit, 'Apply vendor deposit '.$bill->bill_number, [
                ['account_id' => $this->mapping('purchase.accounts_payable'), 'description' => 'Accounts Payable', 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $this->mapping('purchase.vendor_deposit'), 'description' => 'Vendor Deposit', 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ], $bill);

            $allocation = VendorDepositAllocation::query()->create([
                'vendor_deposit_id' => $deposit->id,
                'vendor_bill_id' => $bill->id,
                'allocation_date' => $bill->bill_date,
                'allocated_amount' => $amount,
                'journal_entry_id' => $journal->id,
                'status' => 'posted',
                'created_by' => auth()->id(),
            ]);

            $deposit->allocated_amount = (float) $deposit->allocated_amount + $amount;
            $deposit->remaining_amount = (float) $deposit->remaining_amount - $amount;
            $deposit->status = $deposit->remaining_amount <= 0 ? 'fully_allocated' : 'partially_allocated';
            $deposit->save();

            $bill->applied_vendor_deposit_amount = (float) $bill->applied_vendor_deposit_amount + $amount;
            $bill->paid_amount = (float) $bill->paid_amount + $amount;
            $bill->balance_due = max(0, (float) $bill->balance_due - $amount);
            $bill->status = $bill->balance_due <= 0 ? 'paid' : 'partially_paid';
            $bill->deposit_allocation_journal_entry_id = $journal->id;
            $bill->save();

            return $allocation;
        });
    }

    public function calculateAvailableForPurchaseOrder(PurchaseOrder $order): float
    {
        return (float) VendorDeposit::query()->where('purchase_order_id', $order->id)->whereIn('status', ['posted', 'partially_allocated'])->sum('remaining_amount');
    }

    public function calculateAvailableForVendor(int $vendorId): float
    {
        return (float) VendorDeposit::query()->where('vendor_id', $vendorId)->whereIn('status', ['posted', 'partially_allocated'])->sum('remaining_amount');
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

    private function journal(VendorDeposit $deposit, string $description, array $lines, ?VendorBill $bill = null): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $date = $bill?->bill_date ?? $deposit->deposit_date;
        $journal = JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, (string) $date),
            'journal_date' => $date,
            'description' => $description,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => $bill ? 'vendor_bill' : 'vendor_deposit',
            'source_id' => $bill?->id ?? $deposit->id,
            'source_number' => $bill?->bill_number ?? $deposit->deposit_number,
            'source_revision' => $bill?->revision_no ?? 1,
            'source_module' => 'purchase',
            'is_system_generated' => true,
            'created_by' => auth()->id(),
            'posted_by' => auth()->id(),
            'posted_at' => now(),
        ]);
        $journal->lines()->createMany($lines);
        return $journal->refresh();
    }
}
