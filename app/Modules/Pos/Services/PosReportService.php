<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCustomer;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleLine;
use Carbon\CarbonImmutable;

class PosReportService
{
    /**
     * Ringkasan penjualan kasir untuk rentang tanggal (inklusif).
     *
     * @return array<string,mixed>
     */
    public function summary(string $dateFrom, string $dateTo): array
    {
        $from = CarbonImmutable::parse($dateFrom)->startOfDay();
        $to = CarbonImmutable::parse($dateTo)->endOfDay();

        $sales = PosSale::query()
            ->where('status', 'completed')
            ->whereBetween('sold_at', [$from, $to]);

        $lines = PosSaleLine::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.sale_id')
            ->where('pos_sales.status', 'completed')
            ->whereBetween('pos_sales.sold_at', [$from, $to]);

        $lineRevenue = (float) (clone $lines)->sum('pos_sale_lines.line_total');
        $cost = (float) (clone $lines)->selectRaw('SUM(pos_sale_lines.unit_cost * pos_sale_lines.quantity) as cost')->value('cost');
        $headerDiscount = (float) (clone $sales)->sum('discount_amount');

        $byMethod = (clone $sales)
            ->selectRaw('payment_method, COUNT(*) as count, SUM(total) as total')
            ->groupBy('payment_method')
            ->get()
            ->map(fn ($row) => ['payment_method' => $row->payment_method, 'count' => (int) $row->count, 'total' => round((float) $row->total, 2)])
            ->values()
            ->all();

        // Dikelompokkan di PHP: fungsi tanggal SQLite dan Postgres berbeda.
        $byDay = (clone $sales)->get(['sold_at', 'total'])
            ->groupBy(fn (PosSale $sale) => $sale->sold_at?->format('Y-m-d'))
            ->map(fn ($group, $date) => ['date' => $date, 'count' => $group->count(), 'total' => round((float) $group->sum('total'), 2)])
            ->sortKeys()
            ->values()
            ->all();

        $topProducts = (clone $lines)
            ->selectRaw('pos_sale_lines.product_id, pos_sale_lines.product_name, SUM(pos_sale_lines.quantity) as quantity, SUM(pos_sale_lines.line_total) as revenue')
            ->groupBy('pos_sale_lines.product_id', 'pos_sale_lines.product_name')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'product_id' => $row->product_id ? (int) $row->product_id : null,
                'product_name' => $row->product_name,
                'quantity' => round((float) $row->quantity, 3),
                'revenue' => round((float) $row->revenue, 2),
            ])
            ->values()
            ->all();

        $revenue = $lineRevenue - $headerDiscount;

        return [
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'sales_count' => (int) (clone $sales)->count(),
            'sales_total' => round((float) (clone $sales)->sum('total'), 2),
            'cost_total' => round($cost, 2),
            'gross_profit' => round($revenue - $cost, 2),
            'voided_count' => PosSale::query()->where('status', 'voided')->whereBetween('sold_at', [$from, $to])->count(),
            'by_method' => $byMethod,
            'by_day' => $byDay,
            'top_products' => $topProducts,
            'outstanding_credit' => round((float) PosCustomer::query()->sum('credit_balance'), 2),
        ];
    }
}
