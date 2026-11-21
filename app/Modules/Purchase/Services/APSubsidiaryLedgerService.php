<?php

namespace App\Modules\Purchase\Services;

use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Models\VendorDeposit;
use App\Modules\Purchase\Models\VendorDepositAllocation;
use App\Modules\Purchase\Models\VendorPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class APSubsidiaryLedgerService
{
    public function ledgerByVendor(int $vendorId, array $filters = []): array
    {
        $filters['vendor_id'] = $vendorId;

        return [
            'vendor_id' => $vendorId,
            'movements' => $this->calculateRunningBalance($this->movements($filters)),
        ];
    }

    public function ledgerByBill(int $billId): array
    {
        return [
            'bill_id' => $billId,
            'movements' => $this->calculateRunningBalance($this->movements(['bill_id' => $billId])),
        ];
    }

    public function vendorSummary(array $filters = []): array
    {
        $rows = collect($this->movements($filters));
        $unappliedByVendor = $this->unappliedDepositTotals($rows->pluck('vendor_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all());

        return $rows
            ->groupBy('vendor_id')
            ->map(function (Collection $rows) use ($unappliedByVendor): array {
                $last = $rows->last();
                $officialBalance = round((float) $rows->sum('credit') - (float) $rows->sum('debit'), 2);
                $unappliedDeposit = round((float) ($unappliedByVendor[(int) $last['vendor_id']] ?? 0), 2);

                return [
                    'vendor_id' => $last['vendor_id'],
                    'vendor_name' => $last['vendor_name'],
                    'debit' => round((float) $rows->sum('debit'), 2),
                    'credit' => round((float) $rows->sum('credit'), 2),
                    'balance' => $officialBalance,
                    'gross_ap_outstanding' => $officialBalance,
                    'official_ap_balance' => $officialBalance,
                    'unapplied_deposit_total' => $unappliedDeposit,
                    'net_vendor_exposure' => round($officialBalance - $unappliedDeposit, 2),
                    'ap_accounts' => $rows
                        ->filter(fn (array $row): bool => ! empty($row['ap_account_id']))
                        ->map(fn (array $row): array => [
                            'account_id' => $row['ap_account_id'],
                            'account_code' => $row['ap_account_code'],
                            'account_name' => $row['ap_account_name'],
                        ])
                        ->unique('account_id')
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function openBills(array $filters = [], ?CarbonImmutable $asOf = null): array
    {
        $laterMovements = collect();
        if ($asOf !== null) {
            $filters['end_date'] = $asOf->toDateString();
            $laterFilters = array_intersect_key($filters, array_flip(['vendor_id', 'bill_id']));
            $laterFilters['start_date'] = $asOf->addDay()->toDateString();
            $laterMovements = collect($this->paymentMovements($laterFilters))
                ->merge($this->depositAllocationMovements($laterFilters))
                ->merge($this->returnMovements($laterFilters))
                ->groupBy('bill_id');
        }

        return $this->billBaseQuery($filters)
            ->with('apAccount')
            ->when($asOf === null, fn ($query) => $query->where('balance_due', '>', 0))
            ->orderBy('due_date')
            ->orderBy('bill_date')
            ->get()
            ->map(fn (VendorBill $bill): array => [
                'bill_id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'bill_date' => optional($bill->bill_date)->toDateString(),
                'due_date' => optional($bill->due_date)->toDateString(),
                'vendor_id' => $bill->vendor_id,
                'vendor_name' => $bill->vendor?->name,
                'grand_total' => (float) $bill->grand_total,
                'paid_amount' => (float) $bill->paid_amount,
                'settlement_discount_amount' => (float) $bill->settlement_discount_amount,
                'returned_amount' => (float) $bill->returned_amount,
                'balance_due' => (float) $bill->balance_due,
                'status' => $bill->status,
                'ap_account_id' => $bill->ap_account_id,
                'ap_account_code' => $bill->apAccount?->account_code,
                'ap_account_name' => $bill->apAccount?->account_name,
            ])
            ->map(function (array $row) use ($laterMovements): array {
                $movements = $laterMovements->get($row['bill_id']);
                if ($movements === null) {
                    return $row;
                }

                // Rewind later allocations against the stored totals, including balances imported without payment history.
                $payments = $movements->where('document_type', 'vendor_payment');
                $laterDeposits = (float) $movements->where('document_type', 'vendor_deposit_allocation')->sum('debit');
                $laterReturns = (float) $movements->where('document_type', 'purchase_return')->sum('debit');
                $row['paid_amount'] = max(0, round($row['paid_amount'] - $laterDeposits - (float) $payments->sum('cash_amount'), 2));
                $row['settlement_discount_amount'] = max(0, round($row['settlement_discount_amount'] - (float) $payments->sum('settlement_discount_amount'), 2));
                $row['returned_amount'] = max(0, round($row['returned_amount'] - $laterReturns, 2));
                $row['balance_due'] = max(0, round($row['grand_total'] - $row['paid_amount'] - $row['settlement_discount_amount'] - $row['returned_amount'], 2));
                if ($row['balance_due'] > 0) {
                    $row['status'] = $row['paid_amount'] > 0 ? 'partially_paid' : 'posted';
                }

                return $row;
            })
            ->filter(fn (array $row) => $row['balance_due'] > 0)
            ->values()
            ->all();
    }

    public function movements(array $filters = []): array
    {
        $movements = collect()
            ->merge($this->billMovements($filters))
            ->merge($this->paymentMovements($filters))
            ->merge($this->depositAllocationMovements($filters))
            ->merge($this->returnMovements($filters));

        return $movements
            ->sortBy(fn (array $row): string => $row['date'].'-'.$this->movementSortOrder($row['document_type']).'-'.str_pad((string) $row['document_id'], 12, '0', STR_PAD_LEFT))
            ->values()
            ->all();
    }

    public function calculateRunningBalance(array $movements): array
    {
        $balance = 0.0;

        return array_map(function (array $movement) use (&$balance): array {
            $balance = round($balance + (float) $movement['credit'] - (float) $movement['debit'], 2);
            $movement['balance'] = $balance;

            return $movement;
        }, $movements);
    }

    private function billMovements(array $filters): array
    {
        return $this->billBaseQuery($filters)
            ->with('apAccount')
            ->get()
            ->map(fn (VendorBill $bill): array => $this->movement(
                optional($bill->bill_date)->toDateString(),
                $bill->vendor_id,
                $bill->vendor?->name,
                'vendor_bill',
                $bill->id,
                $bill->bill_number,
                'Vendor bill '.$bill->bill_number,
                0.0,
                (float) $bill->grand_total,
                'vendor_bill',
                $bill->id,
                $bill->ap_account_id,
                $bill->apAccount?->account_code,
                $bill->apAccount?->account_name,
            ))
            ->all();
    }

    private function paymentMovements(array $filters): array
    {
        $filterId = $filters['bill_id'] ?? null;

        return VendorPayment::query()
            ->with('vendor', 'vendorBill.apAccount', 'lines.vendorBill.apAccount')
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->when($filters['vendor_id'] ?? null, fn ($query, $id) => $query->where('vendor_id', $id))
            ->when($filterId, fn ($query, $id) => $query->where(fn ($match) => $match
                ->whereHas('lines', fn ($lines) => $lines->where('vendor_bill_id', $id))
                ->orWhere(fn ($legacy) => $legacy->whereDoesntHave('lines')->where('vendor_bill_id', $id))))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->where('payment_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->where('payment_date', '<=', $date))
            ->get()
            ->flatMap(function (VendorPayment $payment) use ($filterId) {
                $allocations = $payment->lines->isEmpty()
                    ? collect([['document' => $payment->vendorBill, 'cash' => (float) $payment->amount, 'discount' => 0.0]])
                    : $payment->lines
                        ->filter(fn ($line) => $filterId === null || (int) $line->vendor_bill_id === (int) $filterId)
                        ->groupBy('vendor_bill_id')
                        ->map(fn (Collection $lines) => [
                            'document' => $lines->first()->vendorBill,
                            'cash' => (float) $lines->sum('amount'),
                            'discount' => (float) $lines->sum('settlement_discount_amount'),
                        ])->values();

                return $allocations->map(function (array $allocation) use ($payment) {
                    $document = $allocation['document'];
                    $applied = $allocation['cash'] + $allocation['discount'];
                    $description = 'Pembayaran '.$payment->payment_number;
                    if ($document?->bill_number) {
                        $description .= ' untuk '.$document->bill_number;
                    }
                    if ($allocation['discount'] > 0) {
                        $description .= ' (termasuk potongan pelunasan)';
                    }

                    return $this->movement(
                        optional($payment->payment_date)->toDateString(),
                        $payment->vendor_id,
                        $payment->vendor?->name,
                        'vendor_payment',
                        $payment->id,
                        $payment->payment_number,
                        $description,
                        $applied,
                        0.0,
                        'vendor_payment',
                        $payment->id,
                        $document?->ap_account_id,
                        $document?->apAccount?->account_code,
                        $document?->apAccount?->account_name,
                    ) + [
                        'bill_id' => $document?->id,
                        'cash_amount' => round($allocation['cash'], 2),
                        'settlement_discount_amount' => round($allocation['discount'], 2),
                    ];
                });
            })
            ->all();
    }

    private function depositAllocationMovements(array $filters): array
    {
        return VendorDepositAllocation::query()
            ->with('vendorBill.vendor', 'vendorBill.apAccount')
            ->where('status', 'posted')
            ->whereNull('voided_at')
            ->whereHas('vendorBill', fn ($query) => $query->whereNotIn('status', ['void'])->whereNotNull('posted_at'))
            ->when($filters['vendor_id'] ?? null, fn ($query, $vendorId) => $query->whereHas('vendorBill', fn ($bill) => $bill->where('vendor_id', $vendorId)))
            ->when($filters['bill_id'] ?? null, fn ($query, $billId) => $query->where('vendor_bill_id', $billId))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->where('allocation_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->where('allocation_date', '<=', $date))
            ->get()
            ->map(function (VendorDepositAllocation $allocation): array {
                $bill = $allocation->vendorBill;

                return $this->movement(
                    optional($allocation->allocation_date)->toDateString(),
                    $bill?->vendor_id,
                    $bill?->vendor?->name,
                    'vendor_deposit_allocation',
                    $allocation->id,
                    $bill?->bill_number,
                    'Vendor deposit allocation '.$bill?->bill_number,
                    (float) $allocation->allocated_amount,
                    0.0,
                    'vendor_deposit_allocation',
                    $allocation->id,
                    $bill?->ap_account_id,
                    $bill?->apAccount?->account_code,
                    $bill?->apAccount?->account_name,
                ) + ['bill_id' => $allocation->vendor_bill_id];
            })
            ->all();
    }

    private function returnMovements(array $filters): array
    {
        return PurchaseReturn::query()
            ->with('vendor', 'vendorBill.apAccount')
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->when($filters['vendor_id'] ?? null, fn ($query, $vendorId) => $query->where('vendor_id', $vendorId))
            ->when($filters['bill_id'] ?? null, fn ($query, $billId) => $query->where('vendor_bill_id', $billId))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->where('return_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->where('return_date', '<=', $date))
            ->get()
            ->map(fn (PurchaseReturn $return): array => $this->movement(
                optional($return->return_date)->toDateString(),
                $return->vendor_id,
                $return->vendor?->name,
                'purchase_return',
                $return->id,
                $return->return_number,
                'Purchase return '.$return->return_number,
                (float) $return->grand_total,
                0.0,
                'purchase_return',
                $return->id,
                $return->vendorBill?->ap_account_id,
                $return->vendorBill?->apAccount?->account_code,
                $return->vendorBill?->apAccount?->account_name,
            ) + ['bill_id' => $return->vendor_bill_id])
            ->all();
    }

    private function billBaseQuery(array $filters)
    {
        return VendorBill::query()
            ->with('vendor')
            ->whereNotIn('status', ['draft', 'approved', 'void'])
            ->whereNotNull('posted_at')
            ->when($filters['vendor_id'] ?? null, fn ($query, $vendorId) => $query->where('vendor_id', $vendorId))
            ->when($filters['bill_id'] ?? null, fn ($query, $billId) => $query->where('id', $billId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['buyer_id'] ?? null, fn ($query, $buyerId) => $query->where('buyer_id', $buyerId))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->where('bill_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->where('bill_date', '<=', $date));
    }

    private function movement(?string $date, ?int $vendorId, ?string $vendorName, string $documentType, int $documentId, ?string $documentNumber, string $description, float $debit, float $credit, string $sourceType, int $sourceId, ?int $apAccountId = null, ?string $apAccountCode = null, ?string $apAccountName = null): array
    {
        return [
            'date' => $date,
            'vendor_id' => $vendorId,
            'vendor_name' => $vendorName,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'document_number' => $documentNumber,
            'description' => $description,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'balance' => 0.0,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'ap_account_id' => $apAccountId === null ? null : (int) $apAccountId,
            'ap_account_code' => $apAccountCode,
            'ap_account_name' => $apAccountName,
        ];
    }

    private function endDate(array $filters): ?string
    {
        return $filters['end_date'] ?? $filters['as_of_date'] ?? null;
    }

    private function movementSortOrder(string $type): int
    {
        return match ($type) {
            'vendor_bill' => 10,
            'vendor_deposit_allocation' => 20,
            'vendor_payment' => 30,
            'purchase_return' => 40,
            default => 99,
        };
    }

    /**
     * @param  array<int,int>  $vendorIds
     * @return array<int,float>
     */
    private function unappliedDepositTotals(array $vendorIds): array
    {
        if ($vendorIds === []) {
            return [];
        }

        return VendorDeposit::query()
            ->whereIn('vendor_id', $vendorIds)
            ->whereIn('status', ['posted', 'partially_allocated'])
            ->where('remaining_amount', '>', 0)
            ->selectRaw('vendor_id, sum(remaining_amount) as total')
            ->groupBy('vendor_id')
            ->pluck('total', 'vendor_id')
            ->map(fn ($amount) => round((float) $amount, 2))
            ->all();
    }
}
