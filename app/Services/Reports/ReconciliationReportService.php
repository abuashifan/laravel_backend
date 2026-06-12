<?php

namespace App\Services\Reports;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\Product;
use App\Models\Tenant\PurchaseReturn;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesReceipt;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\StockMovementLine;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorDeposit;
use App\Models\Tenant\VendorDepositAllocation;
use App\Models\Tenant\VendorPayment;
use App\Services\Inventory\InventoryValuationService;
use App\Services\Purchase\APSubsidiaryLedgerService;
use App\Services\Sales\ARSubsidiaryLedgerService;
use App\Support\AccountMapping\AccountMappingKey;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ReconciliationReportService
{
    private const TOLERANCE = 0.01;

    public function __construct(
        private readonly ARSubsidiaryLedgerService $arLedger,
        private readonly APSubsidiaryLedgerService $apLedger,
        private readonly InventoryValuationService $inventoryValuation,
    ) {
    }

    public function ar(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $subledgerRows = collect($this->arLedger->customerSummary($filters))->keyBy('customer_id');
        $glRows = $this->glArByCustomer($filters);
        $customerIds = $subledgerRows->keys()->merge($glRows->keys())->filter()->unique()->values();
        $customers = Contact::query()->whereIn('id', $customerIds)->get()->keyBy('id');

        $rows = $customerIds->map(function ($customerId) use ($subledgerRows, $glRows, $customers): array {
            $sub = $subledgerRows->get($customerId, []);
            $subledger = round((float) ($sub['official_ar_balance'] ?? $sub['balance'] ?? 0), 2);
            $gl = round((float) ($glRows->get($customerId) ?? 0), 2);
            $difference = round($subledger - $gl, 2);

            return [
                'customer_id' => (int) $customerId,
                'customer_name' => $sub['customer_name'] ?? $customers->get($customerId)?->name,
                'subledger_ar_balance' => $subledger,
                'gl_ar_balance' => $gl,
                'difference' => $difference,
                'status' => $this->matched($difference) ? 'matched' : 'mismatch',
            ];
        })->values();

        return $this->reconciliationResponse($filters, $this->filterDifferences($rows, $filters), 'subledger_ar_balance', 'gl_ar_balance');
    }

    public function ap(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $subledgerRows = collect($this->apLedger->vendorSummary($filters))->keyBy('vendor_id');
        $glRows = $this->glApByVendor($filters);
        $vendorIds = $subledgerRows->keys()->merge($glRows->keys())->filter()->unique()->values();
        $vendors = Contact::query()->whereIn('id', $vendorIds)->get()->keyBy('id');

        $rows = $vendorIds->map(function ($vendorId) use ($subledgerRows, $glRows, $vendors): array {
            $sub = $subledgerRows->get($vendorId, []);
            $subledger = round((float) ($sub['official_ap_balance'] ?? $sub['balance'] ?? 0), 2);
            $gl = round((float) ($glRows->get($vendorId) ?? 0), 2);
            $difference = round($subledger - $gl, 2);

            return [
                'vendor_id' => (int) $vendorId,
                'vendor_name' => $sub['vendor_name'] ?? $vendors->get($vendorId)?->name,
                'subledger_ap_balance' => $subledger,
                'gl_ap_balance' => $gl,
                'difference' => $difference,
                'status' => $this->matched($difference) ? 'matched' : 'mismatch',
            ];
        })->values();

        return $this->reconciliationResponse($filters, $this->filterDifferences($rows, $filters), 'subledger_ap_balance', 'gl_ap_balance');
    }

    public function inventory(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $valuation = ! empty($filters['as_of_date'])
            ? $this->inventoryValuation->valuationAsOf((string) $filters['as_of_date'], $filters)
            : $this->inventoryValuation->currentValuation($filters);

        $valuationByAccount = $this->valuationByInventoryAccount((array) ($valuation['rows'] ?? []), $filters);
        $glByAccount = $this->glByAccount($this->inventoryAccountIds($filters), $filters, normal: 'debit');
        $accountIds = $valuationByAccount->keys()->merge($glByAccount->keys())->filter()->unique()->values();
        $accounts = ChartOfAccount::query()->whereIn('id', $accountIds)->get()->keyBy('id');

        $rows = $accountIds->map(function ($accountId) use ($valuationByAccount, $glByAccount, $accounts): array {
            $valuationAmount = round((float) ($valuationByAccount->get($accountId) ?? 0), 2);
            $gl = round((float) ($glByAccount->get($accountId) ?? 0), 2);
            $difference = round($valuationAmount - $gl, 2);
            $account = $accounts->get($accountId);

            return [
                'inventory_account_id' => (int) $accountId,
                'inventory_account_code' => $account?->account_code,
                'inventory_account_name' => $account?->account_name,
                'valuation_amount' => $valuationAmount,
                'gl_inventory_balance' => $gl,
                'difference' => $difference,
                'status' => $this->matched($difference) ? 'matched' : 'mismatch',
            ];
        })->values();

        $rows = $this->filterDifferences($rows, $filters);

        return [
            'filters' => $filters,
            'summary' => [
                'total_valuation' => round((float) $rows->sum('valuation_amount'), 2),
                'total_gl_inventory' => round((float) $rows->sum('gl_inventory_balance'), 2),
                'total_difference' => round((float) $rows->sum('difference'), 2),
                'mismatch_count' => $rows->where('status', 'mismatch')->count(),
            ],
            'data' => $rows->values()->all(),
        ];
    }

    public function grni(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $interimId = $this->mappingAccountId(AccountMappingKey::PURCHASE_INVENTORY_INTERIM);
        $rows = collect();

        $query = DB::connection('tenant')->table('goods_receipt_lines as grl')
            ->join('goods_receipts as gr', 'gr.id', '=', 'grl.goods_receipt_id')
            ->leftJoin('contacts as c', 'c.id', '=', 'gr.vendor_id')
            ->leftJoin('products as p', 'p.id', '=', 'grl.product_id')
            ->leftJoin('purchase_order_lines as pol', 'pol.id', '=', 'grl.purchase_order_line_id')
            ->whereNotIn('gr.status', ['draft', 'cancelled', 'void']);

        $this->applyDateFilters($query, 'gr.receipt_date', $filters);
        if (! empty($filters['vendor_id'])) $query->where('gr.vendor_id', (int) $filters['vendor_id']);
        if (! empty($filters['product_id'])) $query->where('grl.product_id', (int) $filters['product_id']);
        if (! empty($filters['warehouse_id'])) $query->where('grl.warehouse_id', (int) $filters['warehouse_id']);

        $records = $query->select([
            'gr.id as goods_receipt_id',
            'gr.receipt_number',
            'gr.receipt_date',
            'gr.vendor_id',
            'c.name as vendor_name',
            'grl.product_id',
            'p.product_name',
            'grl.quantity',
            'grl.billed_quantity',
            'pol.unit_price',
        ])->orderBy('gr.receipt_date')->orderBy('gr.id')->get();

        $glByReceipt = $interimId ? $this->grniGlByReceipt($interimId, $filters) : collect();

        foreach ($records as $record) {
            $received = (float) $record->quantity;
            $billed = (float) $record->billed_quantity;
            $outstandingQty = round($received - $billed, 4);
            $estimated = round($outstandingQty * (float) ($record->unit_price ?? 0), 2);
            $gl = round((float) ($glByReceipt->get((int) $record->goods_receipt_id) ?? 0), 2);
            $difference = round($estimated - $gl, 2);

            $rows->push([
                'goods_receipt_id' => (int) $record->goods_receipt_id,
                'receipt_number' => $record->receipt_number,
                'receipt_date' => (string) $record->receipt_date,
                'vendor_id' => $record->vendor_id ? (int) $record->vendor_id : null,
                'vendor_name' => $record->vendor_name,
                'product_id' => $record->product_id ? (int) $record->product_id : null,
                'product_name' => $record->product_name,
                'received_quantity' => $received,
                'billed_quantity' => $billed,
                'outstanding_quantity' => $outstandingQty,
                'estimated_outstanding_amount' => $estimated,
                'grni_gl_balance_related' => $gl,
                'difference' => $difference,
                'status' => $this->matched($difference) ? 'matched' : 'mismatch',
            ]);
        }

        if ((bool) ($filters['only_difference'] ?? false)) {
            $rows = $rows->filter(fn (array $row): bool => $row['status'] === 'mismatch')->values();
        }

        return [
            'filters' => $filters,
            'summary' => [
                'total_outstanding_quantity' => round((float) $rows->sum('outstanding_quantity'), 4),
                'total_estimated_outstanding_amount' => round((float) $rows->sum('estimated_outstanding_amount'), 2),
                'total_grni_gl_balance_related' => round((float) $rows->sum('grni_gl_balance_related'), 2),
                'mismatch_count' => $rows->where('status', 'mismatch')->count(),
            ],
            'data' => $rows->values()->all(),
        ];
    }

    public function customerDeposits(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $query = CustomerDeposit::query()->with('customer')
            ->whereIn('status', ['posted', 'partially_allocated', 'refunded']);

        $this->applyEloquentDateFilters($query, 'deposit_date', $filters);
        if (! empty($filters['customer_id'])) $query->where('customer_id', (int) $filters['customer_id']);
        if ((bool) ($filters['only_difference'] ?? false)) $query->where('remaining_amount', '!=', 0);

        $rows = $query->orderBy('deposit_date')->get()->map(fn (CustomerDeposit $deposit): array => [
            'customer_id' => (int) $deposit->customer_id,
            'customer_number' => $deposit->customer_number,
            'customer_name' => $deposit->customer?->name,
            'deposit_id' => (int) $deposit->id,
            'deposit_number' => $deposit->deposit_number,
            'deposit_date' => optional($deposit->deposit_date)->toDateString(),
            'amount' => (float) $deposit->amount,
            'allocated_amount' => (float) $deposit->allocated_amount,
            'remaining_amount' => (float) $deposit->remaining_amount,
            'status' => $deposit->status,
            'journal_entry_id' => $deposit->journal_entry_id ? (int) $deposit->journal_entry_id : null,
            'refund_journal_entry_id' => $deposit->refund_journal_entry_id ? (int) $deposit->refund_journal_entry_id : null,
        ])->values();

        return $this->depositResponse($filters, $rows);
    }

    public function vendorDeposits(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $query = VendorDeposit::query()->with('vendor')
            ->whereIn('status', ['posted', 'partially_allocated', 'refunded']);

        $this->applyEloquentDateFilters($query, 'deposit_date', $filters);
        if (! empty($filters['vendor_id'])) $query->where('vendor_id', (int) $filters['vendor_id']);
        if ((bool) ($filters['only_difference'] ?? false)) $query->where('remaining_amount', '!=', 0);

        $rows = $query->orderBy('deposit_date')->get()->map(fn (VendorDeposit $deposit): array => [
            'vendor_id' => (int) $deposit->vendor_id,
            'vendor_number' => $deposit->vendor_number,
            'vendor_name' => $deposit->vendor?->name,
            'deposit_id' => (int) $deposit->id,
            'deposit_number' => $deposit->deposit_number,
            'deposit_date' => optional($deposit->deposit_date)->toDateString(),
            'amount' => (float) $deposit->amount,
            'allocated_amount' => (float) $deposit->allocated_amount,
            'remaining_amount' => (float) $deposit->remaining_amount,
            'status' => $deposit->status,
            'journal_entry_id' => $deposit->journal_entry_id ? (int) $deposit->journal_entry_id : null,
            'refund_journal_entry_id' => $deposit->refund_journal_entry_id ? (int) $deposit->refund_journal_entry_id : null,
        ])->values();

        return $this->depositResponse($filters, $rows);
    }

    private function glArByCustomer(array $filters): Collection
    {
        $accountIds = $this->arAccountIds($filters);
        if ($accountIds === []) return collect();

        $rows = $this->journalSourceBalances($accountIds, $filters, normal: 'debit');

        return $this->balancesByEntity($rows, [
            'sales_invoice' => SalesInvoice::query()->whereIn('id', $this->sourceIds($rows, 'sales_invoice'))->pluck('customer_id', 'id'),
            'sales_receipt' => SalesReceipt::query()->whereIn('id', $this->sourceIds($rows, 'sales_receipt'))->pluck('customer_id', 'id'),
            'sales_return' => SalesReturn::query()->whereIn('id', $this->sourceIds($rows, 'sales_return'))->pluck('customer_id', 'id'),
            'customer_deposit_allocation' => CustomerDepositAllocation::query()
                ->join('sales_invoices', 'sales_invoices.id', '=', 'customer_deposit_allocations.sales_invoice_id')
                ->whereIn('customer_deposit_allocations.id', $this->sourceIds($rows, 'customer_deposit_allocation'))
                ->pluck('sales_invoices.customer_id', 'customer_deposit_allocations.id'),
        ]);
    }

    private function glApByVendor(array $filters): Collection
    {
        $accountIds = $this->apAccountIds($filters);
        if ($accountIds === []) return collect();

        $rows = $this->journalSourceBalances($accountIds, $filters, normal: 'credit');

        return $this->balancesByEntity($rows, [
            'vendor_bill' => VendorBill::query()->whereIn('id', $this->sourceIds($rows, 'vendor_bill'))->pluck('vendor_id', 'id'),
            'vendor_payment' => VendorPayment::query()->whereIn('id', $this->sourceIds($rows, 'vendor_payment'))->pluck('vendor_id', 'id'),
            'purchase_return' => PurchaseReturn::query()->whereIn('id', $this->sourceIds($rows, 'purchase_return'))->pluck('vendor_id', 'id'),
            'vendor_deposit_allocation' => VendorDepositAllocation::query()
                ->join('vendor_bills', 'vendor_bills.id', '=', 'vendor_deposit_allocations.vendor_bill_id')
                ->whereIn('vendor_deposit_allocations.id', $this->sourceIds($rows, 'vendor_deposit_allocation'))
                ->pluck('vendor_bills.vendor_id', 'vendor_deposit_allocations.id'),
        ]);
    }

    private function journalSourceBalances(array $accountIds, array $filters, string $normal): Collection
    {
        $expression = $normal === 'debit'
            ? 'COALESCE(SUM(jel.debit - jel.credit),0)'
            : 'COALESCE(SUM(jel.credit - jel.debit),0)';

        return $this->baseJournalLineQuery($filters)
            ->whereIn('jel.account_id', $accountIds)
            ->whereNotNull('je.source_type')
            ->whereNotNull('je.source_id')
            ->selectRaw("je.source_type, je.source_id, {$expression} as balance")
            ->groupBy('je.source_type', 'je.source_id')
            ->get();
    }

    /**
     * @param array<string, Collection> $sourceEntityMaps
     */
    private function balancesByEntity(Collection $rows, array $sourceEntityMaps): Collection
    {
        $out = collect();
        foreach ($rows as $row) {
            $type = (string) $row->source_type;
            $id = (int) $row->source_id;
            $entityId = $sourceEntityMaps[$type][$id] ?? null;
            if (! $entityId) continue;

            $amount = (float) $row->balance;
            $out[(int) $entityId] = round((float) ($out[(int) $entityId] ?? 0) + $amount, 2);
        }

        return $out;
    }

    private function sourceIds(Collection $rows, string $sourceType): array
    {
        return $rows
            ->where('source_type', $sourceType)
            ->pluck('source_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function valuationByInventoryAccount(array $valuationRows, array $filters): Collection
    {
        $productIds = collect($valuationRows)->pluck('product_id')->filter()->unique()->values()->all();
        $products = Product::query()->whereIn('id', $productIds)->get(['id', 'inventory_account_id'])->keyBy('id');
        $fallback = $this->mappingAccountId(AccountMappingKey::INVENTORY_ASSET);
        $out = collect();

        foreach ($valuationRows as $row) {
            $product = $products->get((int) ($row['product_id'] ?? 0));
            $accountId = (int) ($product?->inventory_account_id ?: $fallback);
            if (! $accountId) continue;
            if (! empty($filters['account_id']) && $accountId !== (int) $filters['account_id']) continue;

            $out[$accountId] = round((float) ($out[$accountId] ?? 0) + (float) ($row['total_value'] ?? 0), 2);
        }

        return $out;
    }

    private function grniGlByReceipt(int $interimAccountId, array $filters): Collection
    {
        $out = collect();

        $stockRows = $this->baseJournalLineQuery($filters)
            ->join('stock_movements as sm', function ($join) {
                $join->on('sm.id', '=', 'je.source_id')->where('je.source_type', '=', 'stock_movement');
            })
            ->where('sm.source_type', 'goods_receipt')
            ->where('jel.account_id', $interimAccountId)
            ->selectRaw('sm.source_id as receipt_id, COALESCE(SUM(jel.credit - jel.debit),0) as balance')
            ->groupBy('sm.source_id')
            ->pluck('balance', 'receipt_id');

        foreach ($stockRows as $receiptId => $balance) {
            $out[(int) $receiptId] = round((float) $balance, 2);
        }

        $billRows = $this->baseJournalLineQuery($filters)
            ->join('vendor_bills as vb', 'vb.journal_entry_id', '=', 'je.id')
            ->whereNotNull('vb.goods_receipt_id')
            ->where('jel.account_id', $interimAccountId)
            ->selectRaw('vb.goods_receipt_id as receipt_id, COALESCE(SUM(jel.credit - jel.debit),0) as balance')
            ->groupBy('vb.goods_receipt_id')
            ->pluck('balance', 'receipt_id');

        foreach ($billRows as $receiptId => $balance) {
            $out[(int) $receiptId] = round((float) ($out[(int) $receiptId] ?? 0) + (float) $balance, 2);
        }

        return $out;
    }

    private function baseJournalLineQuery(array $filters): Builder
    {
        $query = DB::connection('tenant')->table('journal_entry_lines as jel')
            ->join('journal_entries as je', 'je.id', '=', 'jel.journal_entry_id')
            ->where('je.status', 'posted')
            ->where('je.is_obsolete', 0);

        $this->applyDateFilters($query, 'je.journal_date', $filters);
        if (! empty($filters['department_id'])) $query->where('jel.department_id', (int) $filters['department_id']);
        if (! empty($filters['project_id'])) $query->where('jel.project_id', (int) $filters['project_id']);

        return $query;
    }

    private function glByAccount(array $accountIds, array $filters, string $normal): Collection
    {
        if ($accountIds === []) return collect();

        return $this->baseJournalLineQuery($filters)
            ->whereIn('jel.account_id', $accountIds)
            ->selectRaw('jel.account_id, COALESCE(SUM(jel.debit),0) as debit_sum, COALESCE(SUM(jel.credit),0) as credit_sum')
            ->groupBy('jel.account_id')
            ->get()
            ->mapWithKeys(function ($row) use ($normal): array {
                $amount = $normal === 'debit'
                    ? (float) $row->debit_sum - (float) $row->credit_sum
                    : (float) $row->credit_sum - (float) $row->debit_sum;

                return [(int) $row->account_id => round($amount, 2)];
            });
    }

    private function arAccountIds(array $filters): array
    {
        if (! empty($filters['account_id'])) return [(int) $filters['account_id']];

        return collect()
            ->push($this->mappingAccountId(AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE))
            ->merge(SalesInvoice::query()->whereNotNull('ar_account_id')->pluck('ar_account_id')->all())
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function apAccountIds(array $filters): array
    {
        if (! empty($filters['account_id'])) return [(int) $filters['account_id']];

        return collect()
            ->push($this->mappingAccountId(AccountMappingKey::PURCHASE_ACCOUNTS_PAYABLE))
            ->merge(VendorBill::query()->whereNotNull('ap_account_id')->pluck('ap_account_id')->all())
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function inventoryAccountIds(array $filters): array
    {
        if (! empty($filters['account_id'])) return [(int) $filters['account_id']];

        return collect()
            ->push($this->mappingAccountId(AccountMappingKey::INVENTORY_ASSET))
            ->merge(Product::query()->whereNotNull('inventory_account_id')->pluck('inventory_account_id')->all())
            ->merge(StockMovementLine::query()->whereNotNull('inventory_account_id')->pluck('inventory_account_id')->all())
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function mappingAccountId(string $key): ?int
    {
        $id = AccountMapping::query()
            ->where('mapping_key', $key)
            ->where('is_active', true)
            ->value('account_id');

        return $id ? (int) $id : null;
    }

    private function reconciliationResponse(array $filters, Collection $rows, string $subledgerKey, string $glKey): array
    {
        return [
            'filters' => $filters,
            'summary' => [
                'total_subledger' => round((float) $rows->sum($subledgerKey), 2),
                'total_gl' => round((float) $rows->sum($glKey), 2),
                'total_difference' => round((float) $rows->sum('difference'), 2),
                'mismatch_count' => $rows->where('status', 'mismatch')->count(),
            ],
            'data' => $rows->values()->all(),
        ];
    }

    private function depositResponse(array $filters, Collection $rows): array
    {
        return [
            'filters' => $filters,
            'summary' => [
                'total_deposit' => round((float) $rows->sum('amount'), 2),
                'total_allocated' => round((float) $rows->sum('allocated_amount'), 2),
                'total_unapplied' => round((float) $rows->sum('remaining_amount'), 2),
            ],
            'data' => $rows->values()->all(),
        ];
    }

    private function filterDifferences(Collection $rows, array $filters): Collection
    {
        if (! (bool) ($filters['only_difference'] ?? false)) {
            return $rows;
        }

        return $rows->filter(fn (array $row): bool => ! $this->matched((float) ($row['difference'] ?? 0)))->values();
    }

    private function matched(float $amount): bool
    {
        return abs($amount) < self::TOLERANCE;
    }

    private function normalizeFilters(array $filters): array
    {
        if (! empty($filters['date_from']) && empty($filters['start_date'])) {
            $filters['start_date'] = $filters['date_from'];
        }
        if (! empty($filters['date_to']) && empty($filters['end_date'])) {
            $filters['end_date'] = $filters['date_to'];
        }
        if (! empty($filters['as_of_date']) && empty($filters['end_date'])) {
            $filters['end_date'] = $filters['as_of_date'];
        }

        $filters['only_difference'] = (bool) ($filters['only_difference'] ?? false);

        return $filters;
    }

    private function applyDateFilters(Builder $query, string $column, array $filters): void
    {
        if (! empty($filters['start_date'])) $query->whereDate($column, '>=', (string) $filters['start_date']);
        if (! empty($filters['end_date'])) $query->whereDate($column, '<=', (string) $filters['end_date']);
    }

    private function applyEloquentDateFilters($query, string $column, array $filters): void
    {
        if (! empty($filters['start_date'])) $query->where($column, '>=', (string) $filters['start_date']);
        if (! empty($filters['end_date'])) $query->where($column, '<=', (string) $filters['end_date']);
    }
}
