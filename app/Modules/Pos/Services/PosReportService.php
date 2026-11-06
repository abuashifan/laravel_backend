<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCustomer;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleLine;
use App\Modules\Pos\Models\PosSaleReturn;
use App\Modules\Pos\Models\PosSaleReturnLine;
use Carbon\CarbonImmutable;

class PosReportService
{
    public function __construct(private readonly PosClock $clock) {}

    /**
     * Ringkasan penjualan kasir untuk rentang tanggal (inklusif).
     *
     * @return array<string,mixed>
     */
    public function summary(string $dateFrom, string $dateTo): array
    {
        // Batas hari menurut jam toko.
        $from = $this->clock->startOfDay($dateFrom);
        $to = $this->clock->endOfDay($dateTo);

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
            ->groupBy(fn (PosSale $sale) => $sale->sold_at ? $this->clock->format($sale->sold_at, 'Y-m-d') : null)
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

        // Retur dihitung pada tanggal retur. Barang rusak (tidak kembali ke stok)
        // tetap menjadi biaya, jadi hanya HPP barang yang dikembalikan ke stok dikurangi.
        $returns = PosSaleReturn::query()->whereBetween('returned_at', [$from, $to]);
        $returnsTotal = (float) (clone $returns)->sum('total');
        $returnsTax = (float) (clone $returns)->sum('tax_amount');
        $salesTax = (float) (clone $sales)->sum('tax_amount');
        $returnedCost = (float) PosSaleReturnLine::query()
            ->join('pos_sale_returns', 'pos_sale_returns.id', '=', 'pos_sale_return_lines.return_id')
            ->whereBetween('pos_sale_returns.returned_at', [$from, $to])
            ->where('pos_sale_return_lines.restock', true)
            ->selectRaw('SUM(pos_sale_return_lines.unit_cost * pos_sale_return_lines.quantity) as cost')
            ->value('cost');
        $cost -= $returnedCost;

        // Pendapatan bersih = total dibayar pelanggan (termasuk pembulatan) tanpa pajak, dikurangi retur.
        $revenue = (float) (clone $sales)->sum('total') - $salesTax - ($returnsTotal - $returnsTax);

        return [
            'date_from' => CarbonImmutable::parse($dateFrom)->toDateString(),
            'date_to' => CarbonImmutable::parse($dateTo)->toDateString(),
            'sales_count' => (int) (clone $sales)->count(),
            'sales_total' => round((float) (clone $sales)->sum('total'), 2),
            'returns_count' => (int) (clone $returns)->count(),
            'returns_total' => round($returnsTotal, 2),
            'net_sales' => round($revenue, 2),
            'tax_total' => round($salesTax - $returnsTax, 2),
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
