<?php

namespace App\Services\Sales;

use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesReceipt;
use App\Models\Tenant\SalesReturn;
use Illuminate\Support\Collection;

class ARSubsidiaryLedgerService
{
    public function ledgerByCustomer(int $customerId, array $filters = []): array
    {
        $filters['customer_id'] = $customerId;

        return [
            'customer_id' => $customerId,
            'movements' => $this->calculateRunningBalance($this->movements($filters)),
        ];
    }

    public function ledgerByInvoice(int $invoiceId, array $filters = []): array
    {
        return [
            'invoice_id' => $invoiceId,
            'movements' => $this->calculateRunningBalance($this->movements(array_merge($filters, ['invoice_id' => $invoiceId]))),
        ];
    }

    public function customerSummary(array $filters = []): array
    {
        $rows = collect($this->movements($filters));
        $unappliedByCustomer = $this->unappliedDepositTotals($rows->pluck('customer_id')->filter()->unique()->map(fn ($id) => (int) $id)->values()->all());

        return $rows
            ->groupBy('customer_id')
            ->map(function (Collection $rows) use ($unappliedByCustomer): array {
                $last = $rows->last();
                $officialBalance = round((float) $rows->sum('debit') - (float) $rows->sum('credit'), 2);
                $unappliedDeposit = round((float) ($unappliedByCustomer[(int) $last['customer_id']] ?? 0), 2);
                $accounts = $rows
                    ->filter(fn (array $row): bool => ! empty($row['ar_account_id']))
                    ->map(fn (array $row): array => [
                        'account_id' => $row['ar_account_id'],
                        'account_code' => $row['ar_account_code'],
                        'account_name' => $row['ar_account_name'],
                    ])
                    ->unique('account_id')
                    ->values()
                    ->all();

                return [
                    'customer_id' => $last['customer_id'],
                    'customer_name' => $last['customer_name'],
                    'debit' => round((float) $rows->sum('debit'), 2),
                    'credit' => round((float) $rows->sum('credit'), 2),
                    'balance' => $officialBalance,
                    'gross_ar_outstanding' => $officialBalance,
                    'official_ar_balance' => $officialBalance,
                    'unapplied_deposit_total' => $unappliedDeposit,
                    'net_customer_exposure' => round($officialBalance - $unappliedDeposit, 2),
                    'ar_accounts' => $accounts,
                ];
            })
            ->values()
            ->all();
    }

    public function openInvoices(array $filters = []): array
    {
        return $this->invoiceBaseQuery($filters)
            ->with('arAccount')
            ->where('balance_due', '>', 0)
            ->orderBy('due_date')
            ->orderBy('invoice_date')
            ->get()
            ->map(fn (SalesInvoice $invoice): array => [
                'invoice_id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'invoice_date' => optional($invoice->invoice_date)->toDateString(),
                'due_date' => optional($invoice->due_date)->toDateString(),
                'customer_id' => $invoice->customer_id,
                'customer_name' => $invoice->customer?->name,
                'ar_account_id' => $invoice->ar_account_id,
                'ar_account_code' => $invoice->arAccount?->account_code,
                'ar_account_name' => $invoice->arAccount?->account_name,
                'grand_total' => (float) $invoice->grand_total,
                'paid_amount' => (float) $invoice->paid_amount,
                'returned_amount' => (float) $invoice->returned_amount,
                'balance_due' => (float) $invoice->balance_due,
                'status' => $invoice->status,
            ])
            ->all();
    }

    public function movements(array $filters = []): array
    {
        $movements = collect()
            ->merge($this->invoiceMovements($filters))
            ->merge($this->receiptMovements($filters))
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
            $balance = round($balance + (float) $movement['debit'] - (float) $movement['credit'], 2);
            $movement['balance'] = $balance;

            return $movement;
        }, $movements);
    }

    private function invoiceMovements(array $filters): array
    {
        return $this->invoiceBaseQuery($filters)
            ->with('arAccount')
            ->get()
            ->map(fn (SalesInvoice $invoice): array => $this->movement(
                optional($invoice->invoice_date)->toDateString(),
                $invoice->customer_id,
                $invoice->customer?->name,
                'sales_invoice',
                $invoice->id,
                $invoice->invoice_number,
                'Sales invoice '.$invoice->invoice_number,
                (float) $invoice->grand_total,
                0.0,
                'sales_invoice',
                $invoice->id,
                $invoice->ar_account_id,
                $invoice->arAccount?->account_code,
                $invoice->arAccount?->account_name,
            ))
            ->all();
    }

    private function receiptMovements(array $filters): array
    {
        return SalesReceipt::query()
            ->with('customer', 'salesInvoice.arAccount')
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['invoice_id'] ?? null, fn ($query, $invoiceId) => $query->where('sales_invoice_id', $invoiceId))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->whereDate('receipt_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->whereDate('receipt_date', '<=', $date))
            ->get()
            ->map(function (SalesReceipt $receipt): array {
                $invoice = $receipt->salesInvoice;

                return $this->movement(
                    optional($receipt->receipt_date)->toDateString(),
                    $receipt->customer_id,
                    $receipt->customer?->name,
                    'sales_receipt',
                    $receipt->id,
                    $receipt->receipt_number,
                    'Sales receipt '.$receipt->receipt_number,
                    0.0,
                    (float) $receipt->amount,
                    'sales_receipt',
                    $receipt->id,
                    $invoice?->ar_account_id,
                    $invoice?->arAccount?->account_code,
                    $invoice?->arAccount?->account_name,
                );
            })
            ->all();
    }

    private function depositAllocationMovements(array $filters): array
    {
        return CustomerDepositAllocation::query()
            ->with('salesInvoice.customer', 'salesInvoice.arAccount')
            ->where('status', 'posted')
            ->whereNull('voided_at')
            ->whereHas('salesInvoice', fn ($query) => $query->whereNotIn('status', ['void'])->whereNotNull('posted_at'))
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->whereHas('salesInvoice', fn ($invoice) => $invoice->where('customer_id', $customerId)))
            ->when($filters['invoice_id'] ?? null, fn ($query, $invoiceId) => $query->where('sales_invoice_id', $invoiceId))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->whereDate('allocation_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->whereDate('allocation_date', '<=', $date))
            ->get()
            ->map(function (CustomerDepositAllocation $allocation): array {
                $invoice = $allocation->salesInvoice;

                return $this->movement(
                    optional($allocation->allocation_date)->toDateString(),
                    $invoice?->customer_id,
                    $invoice?->customer?->name,
                    'customer_deposit_allocation',
                    $allocation->id,
                    $invoice?->invoice_number,
                    'Customer deposit allocation '.$invoice?->invoice_number,
                    0.0,
                    (float) $allocation->allocated_amount,
                    'customer_deposit_allocation',
                    $allocation->id,
                    $invoice?->ar_account_id,
                    $invoice?->arAccount?->account_code,
                    $invoice?->arAccount?->account_name,
                );
            })
            ->all();
    }

    private function returnMovements(array $filters): array
    {
        return SalesReturn::query()
            ->with('customer', 'salesInvoice.arAccount')
            ->where('status', 'posted')
            ->whereNotNull('posted_at')
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['invoice_id'] ?? null, fn ($query, $invoiceId) => $query->where('sales_invoice_id', $invoiceId))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->whereDate('return_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->whereDate('return_date', '<=', $date))
            ->get()
            ->map(function (SalesReturn $return): array {
                $invoice = $return->salesInvoice;

                return $this->movement(
                    optional($return->return_date)->toDateString(),
                    $return->customer_id,
                    $return->customer?->name,
                    'sales_return',
                    $return->id,
                    $return->return_number,
                    'Sales return '.$return->return_number,
                    0.0,
                    (float) $return->grand_total,
                    'sales_return',
                    $return->id,
                    $invoice?->ar_account_id,
                    $invoice?->arAccount?->account_code,
                    $invoice?->arAccount?->account_name,
                );
            })
            ->all();
    }

    private function invoiceBaseQuery(array $filters)
    {
        return SalesInvoice::query()
            ->with('customer')
            ->whereNotIn('status', ['draft', 'approved', 'void'])
            ->whereNotNull('posted_at')
            ->when($filters['customer_id'] ?? null, fn ($query, $customerId) => $query->where('customer_id', $customerId))
            ->when($filters['invoice_id'] ?? null, fn ($query, $invoiceId) => $query->where('id', $invoiceId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['salesperson_id'] ?? null, fn ($query, $salespersonId) => $query->where('salesperson_id', $salespersonId))
            ->when($filters['start_date'] ?? null, fn ($query, $date) => $query->whereDate('invoice_date', '>=', $date))
            ->when($this->endDate($filters), fn ($query, $date) => $query->whereDate('invoice_date', '<=', $date));
    }

    private function movement(?string $date, ?int $customerId, ?string $customerName, string $documentType, int $documentId, ?string $documentNumber, string $description, float $debit, float $credit, string $sourceType, int $sourceId, int|string|null $arAccountId = null, ?string $arAccountCode = null, ?string $arAccountName = null): array
    {
        return [
            'date' => $date,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'document_type' => $documentType,
            'document_id' => $documentId,
            'document_number' => $documentNumber,
            'description' => $description,
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'balance' => 0.0,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'ar_account_id' => $arAccountId === null ? null : (int) $arAccountId,
            'ar_account_code' => $arAccountCode,
            'ar_account_name' => $arAccountName,
        ];
    }

    private function endDate(array $filters): ?string
    {
        return $filters['end_date'] ?? $filters['as_of_date'] ?? null;
    }

    private function movementSortOrder(string $type): int
    {
        return match ($type) {
            'sales_invoice' => 10,
            'sales_receipt' => 20,
            'customer_deposit_allocation' => 30,
            'sales_return' => 40,
            default => 99,
        };
    }

    /**
     * @param  array<int,int>  $customerIds
     * @return array<int,float>
     */
    private function unappliedDepositTotals(array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        return CustomerDeposit::query()
            ->whereIn('customer_id', $customerIds)
            ->whereIn('status', ['posted', 'partially_allocated'])
            ->where('remaining_amount', '>', 0)
            ->selectRaw('customer_id, sum(remaining_amount) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id')
            ->map(fn ($amount) => round((float) $amount, 2))
            ->all();
    }
}
