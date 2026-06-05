<?php

namespace App\Services\Purchase;

use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorDepositAllocation;
use App\Models\Tenant\VendorPayment;
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
        return collect($this->movements($filters))
            ->groupBy('vendor_id')
            ->map(function (Collection $rows): array {
                $last = $rows->last();

                return [
                    'vendor_id' => $last['vendor_id'],
                    'vendor_name' => $last['vendor_name'],
                    'debit' => round((float) $rows->sum('debit'), 2),
                    'credit' => round((float) $rows->sum('credit'), 2),
                    'balance' => round((float) $rows->sum('credit') - (float) $rows->sum('debit'), 2),
                ];
            })
            ->values()
            ->all();
    }

    public function openBills(array $filters = []): array
    {
        return $this->billBaseQuery($filters)
            ->where('balance_due', '>', 0)
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
                'returned_amount' => (float) $bill->returned_amount,
                'balance_due' => (float) $bill->balance_due,
                'status' => $bill->status,
            ])
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
            ))
            ->all();
    }

    private function paymentMovements(array $filters): array
    {
        return VendorPayment::query()
            ->with('vendor')
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->when($filters['vendor_id'] ?? null, fn ($query, $vendorId) => $query->where('vendor_id', $vendorId))
            ->when($filters['bill_id'] ?? null, fn ($query, $billId) => $query->where('vendor_bill_id', $billId))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->where('payment_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->where('payment_date', '<=', $date))
            ->get()
            ->map(fn (VendorPayment $payment): array => $this->movement(
                optional($payment->payment_date)->toDateString(),
                $payment->vendor_id,
                $payment->vendor?->name,
                'vendor_payment',
                $payment->id,
                $payment->payment_number,
                'Vendor payment '.$payment->payment_number,
                (float) $payment->amount,
                0.0,
                'vendor_payment',
                $payment->id,
            ))
            ->all();
    }

    private function depositAllocationMovements(array $filters): array
    {
        return VendorDepositAllocation::query()
            ->with('vendorBill.vendor')
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
                );
            })
            ->all();
    }

    private function returnMovements(array $filters): array
    {
        return PurchaseReturn::query()
            ->with('vendor')
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
            ))
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

    private function movement(?string $date, ?int $vendorId, ?string $vendorName, string $documentType, int $documentId, ?string $documentNumber, string $description, float $debit, float $credit, string $sourceType, int $sourceId): array
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
}
