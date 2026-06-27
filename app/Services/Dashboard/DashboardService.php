<?php

namespace App\Services\Dashboard;

use App\Data\Reports\CashFlowFilter;
use App\Data\Reports\FinancialSummaryFilter;
use App\Models\ActivityLog;
use App\Models\FiscalYear;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesReceipt;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\VendorBill;
use App\Models\Tenant\VendorPayment;
use App\Services\Reports\CashFlowService;
use App\Services\Reports\FinancialSummaryService;
use App\Services\Tenant\TenantContext;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class DashboardService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly FinancialSummaryService $financialSummaryService,
        private readonly CashFlowService $cashFlowService,
    ) {
    }

    public function summary(): array
    {
        $range = $this->currentRange();
        $financial = $this->financialSummaryService->getSummary(new FinancialSummaryFilter(
            startDate: $range['start_date'],
            endDate: $range['end_date'],
            asOfDate: $range['end_date'],
        ));

        $cashFlow = $this->cashFlowService->getCashFlow(new CashFlowFilter(
            startDate: $range['start_date'],
            endDate: $range['end_date'],
            includeAccountBreakdown: false,
        ));

        $profitLoss = (bool) ($financial['valid'] ?? false)
            ? (float) ($financial['profit_loss']['net_profit_or_loss'] ?? 0)
            : 0.0;
        $endingCash = (bool) ($cashFlow['valid'] ?? false)
            ? (float) ($cashFlow['summary']['ending_cash_balance'] ?? 0)
            : 0.0;

        return [
            'total_receivable' => round((float) SalesInvoice::query()->whereIn('status', ['posted', 'partially_paid', 'paid'])->sum('balance_due'), 2),
            'receivable_trend' => null,
            'total_payable' => round((float) VendorBill::query()->whereIn('status', ['posted', 'partially_paid', 'paid'])->sum('balance_due'), 2),
            'payable_trend' => null,
            'cash_balance' => round($endingCash, 2),
            'current_month_profit' => round($profitLoss, 2),
            'profit_trend' => null,
        ];
    }

    public function pending(): array
    {
        $company = $this->tenantContext->company();
        $fiscalYear = $company
            ? FiscalYear::query()->where('company_id', $company->id)->where('status', 'open')->orderByDesc('year')->first()
            : null;

        $daysRemaining = null;
        if ($fiscalYear?->end_date) {
            $daysRemaining = max(0, Carbon::today()->diffInDays(Carbon::parse((string) $fiscalYear->end_date), false));
        }

        return [
            'pending_invoices' => SalesInvoice::query()->whereIn('status', ['draft', 'approved'])->count(),
            'pending_bills' => VendorBill::query()->whereIn('status', ['draft', 'approved'])->count(),
            'low_stock_count' => StockBalance::query()
                ->whereHas('product', fn ($query) => $query->where('is_stock_item', true))
                ->where('quantity_available', '<=', 0)
                ->count(),
            'fiscal_year_days_remaining' => $daysRemaining,
        ];
    }

    public function chartData(): array
    {
        $months = collect(range(5, 0))->map(function (int $offset): array {
            $monthStart = Carbon::now()->startOfMonth()->subMonthsNoOverflow($offset);
            $monthEnd = $monthStart->copy()->endOfMonth();

            return [
                'month' => $monthStart->format('Y-m'),
                'sales' => round((float) SalesInvoice::query()
                    ->whereIn('status', ['posted', 'partially_paid', 'paid'])
                    ->whereDate('invoice_date', '>=', $monthStart->toDateString())
                    ->whereDate('invoice_date', '<=', $monthEnd->toDateString())
                    ->sum('grand_total'), 2),
                'purchase' => round((float) VendorBill::query()
                    ->whereIn('status', ['posted', 'partially_paid', 'paid'])
                    ->whereDate('bill_date', '>=', $monthStart->toDateString())
                    ->whereDate('bill_date', '<=', $monthEnd->toDateString())
                    ->sum('grand_total'), 2),
                'cash_in' => round((float) SalesReceipt::query()
                    ->where('status', 'posted')
                    ->whereDate('receipt_date', '>=', $monthStart->toDateString())
                    ->whereDate('receipt_date', '<=', $monthEnd->toDateString())
                    ->sum('amount'), 2),
                'cash_out' => round((float) VendorPayment::query()
                    ->where('status', 'posted')
                    ->whereDate('payment_date', '>=', $monthStart->toDateString())
                    ->whereDate('payment_date', '<=', $monthEnd->toDateString())
                    ->sum('amount'), 2),
            ];
        })->values();

        return [
            'sales_purchase' => $months->map(fn (array $month): array => [
                'month' => $month['month'],
                'penjualan' => $month['sales'],
                'pembelian' => $month['purchase'],
            ])->all(),
            'cash_flow' => $months->map(fn (array $month): array => [
                'month' => $month['month'],
                'masuk' => $month['cash_in'],
                'keluar' => $month['cash_out'],
            ])->all(),
        ];
    }

    public function recentActivities(): array
    {
        return ActivityLog::query()
            ->with('user:id,name,email')
            ->where('company_id', $this->tenantContext->companyId())
            ->whereIn('module', ['sales', 'purchase', 'cash_bank', 'inventory', 'fixed_assets', 'accounting', 'settings'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(fn (ActivityLog $log): array => $this->activityPayload($log))
            ->all();
    }

    private function currentRange(): array
    {
        $now = Carbon::now();

        return [
            'start_date' => $now->copy()->startOfMonth()->toDateString(),
            'end_date' => $now->copy()->endOfMonth()->toDateString(),
        ];
    }

    private function activityPayload(ActivityLog $log): array
    {
        $sourceType = (string) data_get($log, 'properties.source.source_type', $this->sourceTypeForActivity($log));
        $subjectType = class_basename((string) $log->subject_type);
        $subjectId = (int) ($log->subject_id ?? 0);
        $title = $this->activityTitle($log);
        $subtitle = trim(implode(' · ', array_filter([
            $log->user?->name,
            $this->recordNumber($log),
        ])));

        return [
            'id' => (int) $log->id,
            'title' => $title,
            'subtitle' => $subtitle !== '' ? $subtitle : $subjectType,
            'amount' => round((float) $this->activityAmount($log), 2),
            'status' => $this->activityStatus($log),
            'source_type' => $sourceType,
            'source_id' => $subjectId,
            'href' => $this->activityHref($sourceType, $subjectType, $subjectId),
            'created_at' => optional($log->created_at)->toIso8601String() ?? now()->toIso8601String(),
        ];
    }

    private function sourceTypeForActivity(ActivityLog $log): string
    {
        $subjectType = class_basename((string) $log->subject_type);

        return match ($subjectType) {
            'SalesInvoice' => 'sales.invoice',
            'SalesReceipt' => 'sales.receipt',
            'SalesOrder' => 'sales.order',
            'DeliveryOrder' => 'sales.delivery_order',
            'ProformaInvoice' => 'sales.proforma_invoice',
            'VendorBill' => 'purchase.bill',
            'VendorPayment' => 'purchase.payment',
            'PurchaseOrder' => 'purchase.order',
            'GoodsReceipt' => 'purchase.goods_receipt',
            'FixedAsset' => 'fixed_assets.asset',
            'JournalEntry' => 'accounting.journal',
            'StockMovement' => 'inventory.movement',
            'StockOpname' => 'inventory.opname',
            default => (string) $log->module,
        };
    }

    private function activityTitle(ActivityLog $log): string
    {
        if (! empty($log->description)) {
            return (string) $log->description;
        }

        return str((string) $log->action)->replace(['.', '_'], ' ')->title()->toString();
    }

    private function recordNumber(ActivityLog $log): ?string
    {
        return data_get($log, 'properties.record_number') ? (string) data_get($log, 'properties.record_number') : null;
    }

    private function activityAmount(ActivityLog $log): float
    {
        foreach ([
            'properties.new_values.grand_total',
            'properties.new_values.amount',
            'properties.metadata.amount',
            'properties.metadata.total_amount',
        ] as $path) {
            $value = data_get($log, $path);
            if (is_numeric($value)) {
                return (float) $value;
            }
        }

        return 0.0;
    }

    private function activityStatus(ActivityLog $log): string
    {
        $action = mb_strtolower((string) $log->action);

        return match (true) {
            str_contains($action, 'void') => 'void',
            str_contains($action, 'reopen') => 'reopened',
            str_contains($action, 'approve') => 'approved',
            str_contains($action, 'post') => 'posted',
            str_contains($action, 'create') => 'draft',
            default => 'completed',
        };
    }

    private function activityHref(string $sourceType, string $subjectType, int $subjectId): string
    {
        return match (true) {
            $sourceType === 'sales.invoice' || $subjectType === 'SalesInvoice' => '/sales/invoices/'.$subjectId,
            $sourceType === 'sales.receipt' || $subjectType === 'SalesReceipt' => '/sales/receipts/'.$subjectId,
            $sourceType === 'sales.order' || $subjectType === 'SalesOrder' => '/sales/orders/'.$subjectId,
            $sourceType === 'sales.delivery_order' || $subjectType === 'DeliveryOrder' => '/sales/delivery-orders/'.$subjectId,
            $sourceType === 'purchase.bill' || $subjectType === 'VendorBill' => '/purchase/bills/'.$subjectId,
            $sourceType === 'purchase.payment' || $subjectType === 'VendorPayment' => '/purchase/payments/'.$subjectId,
            $sourceType === 'purchase.order' || $subjectType === 'PurchaseOrder' => '/purchase/orders/'.$subjectId,
            $sourceType === 'purchase.goods_receipt' || $subjectType === 'GoodsReceipt' => '/purchase/goods-receipts/'.$subjectId,
            $sourceType === 'fixed_assets.asset' || $subjectType === 'FixedAsset' => '/fixed-assets/'.$subjectId,
            $sourceType === 'accounting.journal' || $subjectType === 'JournalEntry' => '/accounting/journals/'.$subjectId,
            $sourceType === 'inventory.movement' || $subjectType === 'StockMovement' => '/inventory/stock-movements/'.$subjectId,
            $sourceType === 'inventory.opname' || $subjectType === 'StockOpname' => '/inventory/stock-opnames/'.$subjectId,
            default => '/settings/audit',
        };
    }
}