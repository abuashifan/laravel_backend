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
        private readonly PurchaseAccountResolverService $accountResolver,
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

    public function availableForVendor(int $vendorId, array $filters = []): array
    {
        $purchaseOrderId = isset($filters['purchase_order_id']) ? (int) $filters['purchase_order_id'] : null;

        $deposits = VendorDeposit::query()
            ->with('purchaseOrder')
            ->where('vendor_id', $vendorId)
            ->whereIn('status', ['posted', 'partially_allocated'])
            ->where('remaining_amount', '>', 0)
            ->when($purchaseOrderId, fn ($query) => $query->orderByRaw('case when purchase_order_id = ? then 0 else 1 end', [$purchaseOrderId]))
            ->orderBy('deposit_date')
            ->orderBy('id')
            ->get()
            ->map(fn (VendorDeposit $deposit): array => $this->availableDepositRow($deposit, $purchaseOrderId))
            ->values()
            ->all();

        return [
            'vendor_id' => $vendorId,
            'unapplied_total' => round((float) collect($deposits)->sum('remaining_amount'), 2),
            'deposits' => $deposits,
        ];
    }

    public function availableForPurchaseOrder(int $purchaseOrderId): array
    {
        $order = PurchaseOrder::query()->findOrFail($purchaseOrderId);

        return $this->availableForVendor((int) $order->vendor_id, ['purchase_order_id' => $order->id]);
    }

    public function availableForBill(int $vendorBillId): array
    {
        $bill = VendorBill::query()->findOrFail($vendorBillId);

        return $this->availableForVendor((int) $bill->vendor_id, [
            'purchase_order_id' => $bill->purchase_order_id,
        ]);
    }

    public function calculateUnappliedTotalForVendor(int $vendorId): float
    {
        return round((float) VendorDeposit::query()
            ->where('vendor_id', $vendorId)
            ->whereIn('status', ['posted', 'partially_allocated'])
            ->where('remaining_amount', '>', 0)
            ->sum('remaining_amount'), 2);
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

    public function allocateToBill(VendorDeposit $deposit, VendorBill $bill, float $amount, array $options = []): VendorDepositAllocation
    {
        if ($deposit->vendor_id !== $bill->vendor_id) throw ApiException::make('VENDOR_MISMATCH', 'Deposit and bill vendor mismatch.', 422);
        if (! in_array($deposit->status, ['posted', 'partially_allocated'], true)) throw ApiException::make('VENDOR_DEPOSIT_NOT_AVAILABLE', 'Vendor deposit is not available for allocation.', 422);
        if (! in_array($bill->status, ['posted', 'partially_paid'], true) || ! $bill->posted_at) throw ApiException::make('VENDOR_BILL_NOT_PAYABLE', 'Vendor bill must be posted before deposit allocation.', 422);
        if ($amount > (float) $deposit->remaining_amount) throw ApiException::make('VENDOR_DEPOSIT_INSUFFICIENT', 'Cannot allocate more than remaining deposit.', 422);
        if ($amount > (float) $bill->balance_due) throw ApiException::make('VENDOR_DEPOSIT_ALLOCATION_EXCEEDS_BILL', 'Allocation exceeds bill balance.', 422);
        $allocationDate = (string) ($options['allocation_date'] ?? $bill->bill_date);
        $this->guardDate($allocationDate);

        return DB::connection('tenant')->transaction(function () use ($deposit, $bill, $amount, $allocationDate, $options) {
            $lockedDeposit = VendorDeposit::query()->lockForUpdate()->findOrFail($deposit->id);
            $lockedBill = VendorBill::query()->lockForUpdate()->findOrFail($bill->id);

            if ($lockedDeposit->vendor_id !== $lockedBill->vendor_id) throw ApiException::make('VENDOR_MISMATCH', 'Deposit and bill vendor mismatch.', 422);
            if (! in_array($lockedDeposit->status, ['posted', 'partially_allocated'], true)) throw ApiException::make('VENDOR_DEPOSIT_NOT_AVAILABLE', 'Vendor deposit is not available for allocation.', 422);
            if (! in_array($lockedBill->status, ['posted', 'partially_paid'], true) || ! $lockedBill->posted_at) throw ApiException::make('VENDOR_BILL_NOT_PAYABLE', 'Vendor bill must be posted before deposit allocation.', 422);
            if ($amount > (float) $lockedDeposit->remaining_amount) throw ApiException::make('VENDOR_DEPOSIT_INSUFFICIENT', 'Cannot allocate more than remaining deposit.', 422);
            if ($amount > (float) $lockedBill->balance_due) throw ApiException::make('VENDOR_DEPOSIT_ALLOCATION_EXCEEDS_BILL', 'Allocation exceeds bill balance.', 422);

            $journal = $this->journal($lockedDeposit, 'Apply vendor deposit '.$lockedBill->bill_number, [
                ['account_id' => $this->accountResolver->resolveBillPayableAccountId($lockedBill), 'description' => 'Accounts Payable', 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $this->mapping('purchase.vendor_deposit'), 'description' => 'Vendor Deposit', 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ], $lockedBill, $allocationDate);

            $metadata = array_filter([
                'source_context' => $options['source_context'] ?? null,
                'notes' => $options['notes'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $allocation = VendorDepositAllocation::query()->create([
                'vendor_deposit_id' => $lockedDeposit->id,
                'vendor_bill_id' => $lockedBill->id,
                'allocation_date' => $allocationDate,
                'allocated_amount' => $amount,
                'journal_entry_id' => $journal->id,
                'status' => 'posted',
                'metadata' => $metadata === [] ? null : $metadata,
                'created_by' => auth()->id(),
            ]);
            $journal->source_id = $allocation->id;
            $journal->save();

            $lockedDeposit->allocated_amount = (float) $lockedDeposit->allocated_amount + $amount;
            $lockedDeposit->remaining_amount = (float) $lockedDeposit->remaining_amount - $amount;
            $lockedDeposit->status = $lockedDeposit->remaining_amount <= 0 ? 'fully_allocated' : 'partially_allocated';
            $lockedDeposit->save();

            $lockedBill->applied_vendor_deposit_amount = (float) $lockedBill->applied_vendor_deposit_amount + $amount;
            $lockedBill->paid_amount = (float) $lockedBill->paid_amount + $amount;
            $lockedBill->balance_due = max(0, (float) $lockedBill->balance_due - $amount);
            $lockedBill->status = $lockedBill->balance_due <= 0 ? 'paid' : 'partially_paid';
            $lockedBill->deposit_allocation_journal_entry_id = $journal->id;
            $lockedBill->save();
            $this->auditPurchase($this->auditLogService, 'vendor_deposit.allocated', $lockedDeposit, 'deposit_number', ['allocation_id' => $allocation->id, 'vendor_bill_id' => $lockedBill->id, 'amount' => $amount, 'source_context' => $options['source_context'] ?? null]);

            return $allocation->refresh()->load('vendorDeposit', 'vendorBill');
        });
    }

    public function voidAllocation(VendorDepositAllocation $allocation, ?string $reason = null): VendorDepositAllocation
    {
        if ($allocation->status === 'void') throw ApiException::make('VENDOR_DEPOSIT_ALLOCATION_ALREADY_VOID', 'Vendor deposit allocation already void.', 422);
        $reason = $this->voidEffectService->requireReason($reason);
        $this->guardDate((string) $allocation->allocation_date, 'void');

        return DB::connection('tenant')->transaction(function () use ($allocation, $reason) {
            $lockedAllocation = VendorDepositAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $deposit = VendorDeposit::query()->lockForUpdate()->findOrFail($lockedAllocation->vendor_deposit_id);
            $bill = VendorBill::query()->lockForUpdate()->find($lockedAllocation->vendor_bill_id);
            $amount = (float) $lockedAllocation->allocated_amount;

            if ($bill && $bill->status !== 'void') {
                $bill->paid_amount = max(0, (float) $bill->paid_amount - $amount);
                $bill->applied_vendor_deposit_amount = max(0, (float) $bill->applied_vendor_deposit_amount - $amount);
                $bill->balance_due = min((float) $bill->grand_total, (float) $bill->balance_due + $amount);
                $bill->status = $bill->paid_amount > 0 ? 'partially_paid' : 'posted';
                $bill->save();
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

    private function availableDepositRow(VendorDeposit $deposit, ?int $purchaseOrderId = null): array
    {
        $matchesPurchaseOrder = $purchaseOrderId !== null && (int) $deposit->purchase_order_id === $purchaseOrderId;

        return [
            'id' => $deposit->id,
            'deposit_number' => $deposit->deposit_number,
            'deposit_date' => optional($deposit->deposit_date)->toDateString(),
            'vendor_id' => $deposit->vendor_id,
            'amount' => (float) $deposit->amount,
            'allocated_amount' => (float) $deposit->allocated_amount,
            'remaining_amount' => (float) $deposit->remaining_amount,
            'purchase_order_id' => $deposit->purchase_order_id,
            'purchase_order_number' => $deposit->purchaseOrder?->order_number,
            'match_strength' => $matchesPurchaseOrder ? 'purchase_order' : 'vendor_only',
        ];
    }

    private function journal(VendorDeposit $deposit, string $description, array $lines, ?VendorBill $bill = null, ?string $journalDate = null): JournalEntry
    {
        $company = $this->tenantContext->company();
        if (! $company) throw ApiException::make('COMPANY_NOT_FOUND', 'Company context not resolved.', 422);
        $date = $journalDate ?? (string) ($bill?->bill_date ?? $deposit->deposit_date);
        $journal = JournalEntry::query()->create([
            'journal_number' => $this->documentNumberService->generate($company, DocumentType::JOURNAL_ENTRY, $date),
            'journal_date' => $date,
            'description' => $description,
            'status' => 'posted',
            'revision_no' => 1,
            'source_type' => $bill ? 'vendor_deposit_allocation' : 'vendor_deposit',
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
