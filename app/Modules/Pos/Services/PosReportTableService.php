<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosCategory;
use App\Modules\Pos\Models\PosCustomer;
use App\Modules\Pos\Models\PosProduct;
use App\Modules\Pos\Models\PosSale;
use App\Modules\Pos\Models\PosSaleLine;
use App\Modules\Pos\Models\PosSaleReturn;
use App\Modules\Pos\Models\PosSaleReturnLine;
use App\Modules\Pos\Models\PosShift;
use Carbon\CarbonImmutable;

/**
 * Laporan rinci Fase 4a. Setiap laporan berbentuk tabel yang sama
 * (`columns` + `rows` + `totals`), jadi layar laporan dan ekspor Excel memakai
 * satu jalur. Tipe kolom: text, money, number, percent, datetime, date.
 */
class PosReportTableService
{
    public const TYPES = ['cashiers', 'shifts', 'products', 'categories', 'hours', 'low-stock', 'credit-aging'];

    /** Rentang hari penjualan untuk rata-rata harian di laporan stok menipis. */
    private const SALES_WINDOW_DAYS = 14;

    public function __construct(private readonly PosClock $clock) {}

    /**
     * @return array{type: string, title: string, columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, totals: array<string, mixed>|null, date_from: string, date_to: string}
     */
    public function table(string $type, string $dateFrom, string $dateTo): array
    {
        $from = $this->clock->startOfDay($dateFrom);
        $to = $this->clock->endOfDay($dateTo);

        [$title, $columns, $rows, $sumKeys] = match ($type) {
            'cashiers' => $this->cashiers($from, $to),
            'shifts' => $this->shifts($from, $to),
            'products' => $this->products($from, $to),
            'categories' => $this->categories($from, $to),
            'hours' => $this->hours($from, $to),
            'low-stock' => $this->lowStock(),
            'credit-aging' => $this->creditAging(),
        };

        $totals = null;
        if ($sumKeys !== [] && $rows !== []) {
            $totals = [];
            foreach ($sumKeys as $key) {
                $totals[$key] = round(array_sum(array_column($rows, $key)), 2);
            }
            if (isset($totals['profit'], $totals['revenue'])) {
                $totals['margin'] = $totals['revenue'] > 0 ? round($totals['profit'] / $totals['revenue'] * 100, 1) : null;
            }
        }

        return [
            'type' => $type,
            'title' => $title,
            'columns' => array_map(fn ($column) => ['key' => $column[0], 'label' => $column[1], 'type' => $column[2]], $columns),
            'rows' => array_values($rows),
            'totals' => $totals,
            'date_from' => CarbonImmutable::parse($dateFrom)->toDateString(),
            'date_to' => CarbonImmutable::parse($dateTo)->toDateString(),
        ];
    }

    /** Nilai per baris dalam urutan kolom, untuk ekspor Excel. */
    public static function exportRows(array $table): array
    {
        $keys = array_column($table['columns'], 'key');
        $rows = array_map(fn ($row) => array_map(fn ($key) => $row[$key] ?? null, $keys), $table['rows']);
        if ($table['totals']) {
            $rows[] = array_map(fn ($key, $index) => $index === 0 ? 'Total' : ($table['totals'][$key] ?? null), $keys, array_keys($keys));
        }

        return $rows;
    }

    // ---- Laporan ----

    private function cashiers(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $row = function (int $userId, ?string $name) use (&$rows) {
            return $rows[$userId] ??= [
                'user_name' => $name ?? 'Pengguna #'.$userId, 'sales_count' => 0, 'sales_total' => 0.0, 'average' => 0.0,
                'voided_count' => 0, 'returns_total' => 0.0, 'shifts' => 0, 'cash_difference' => 0.0,
            ];
        };

        $sales = PosSale::query()->where('status', 'completed')->whereBetween('sold_at', [$from, $to])
            ->selectRaw('user_id, MAX(user_name) as user_name, COUNT(*) as count, SUM(total) as total')
            ->groupBy('user_id')->get();
        foreach ($sales as $sale) {
            $row((int) $sale->user_id, $sale->user_name);
            $rows[$sale->user_id]['sales_count'] = (int) $sale->count;
            $rows[$sale->user_id]['sales_total'] = round((float) $sale->total, 2);
            $rows[$sale->user_id]['average'] = $sale->count > 0 ? round((float) $sale->total / $sale->count, 2) : 0.0;
        }
        foreach (PosSale::query()->where('status', 'voided')->whereBetween('sold_at', [$from, $to])
            ->selectRaw('user_id, MAX(user_name) as user_name, COUNT(*) as count')->groupBy('user_id')->get() as $voided) {
            $row((int) $voided->user_id, $voided->user_name);
            $rows[$voided->user_id]['voided_count'] = (int) $voided->count;
        }
        foreach (PosSaleReturn::query()->whereBetween('returned_at', [$from, $to])
            ->selectRaw('user_id, MAX(user_name) as user_name, SUM(total) as total')->groupBy('user_id')->get() as $return) {
            $row((int) $return->user_id, $return->user_name);
            $rows[$return->user_id]['returns_total'] = round((float) $return->total, 2);
        }
        // Selisih laci dari shift yang ditutup dalam rentang.
        foreach (PosShift::query()->where('status', 'closed')->whereBetween('closed_at', [$from, $to])
            ->selectRaw('user_id, MAX(user_name) as user_name, COUNT(*) as count, SUM(cash_difference) as difference')->groupBy('user_id')->get() as $shift) {
            $row((int) $shift->user_id, $shift->user_name);
            $rows[$shift->user_id]['shifts'] = (int) $shift->count;
            $rows[$shift->user_id]['cash_difference'] = round((float) $shift->difference, 2);
        }

        usort($rows, fn ($a, $b) => $b['sales_total'] <=> $a['sales_total']);

        return ['Penjualan per kasir', [
            ['user_name', 'Kasir', 'text'],
            ['sales_count', 'Transaksi', 'number'],
            ['sales_total', 'Penjualan', 'money'],
            ['average', 'Rata-rata', 'money'],
            ['voided_count', 'Dibatalkan', 'number'],
            ['returns_total', 'Retur', 'money'],
            ['shifts', 'Shift ditutup', 'number'],
            ['cash_difference', 'Selisih laci', 'money'],
        ], $rows, ['sales_count', 'sales_total', 'voided_count', 'returns_total', 'shifts', 'cash_difference']];
    }

    private function shifts(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $shifts = PosShift::query()->whereBetween('opened_at', [$from, $to])->orderByDesc('opened_at')->get();
        $sales = PosSale::query()->where('status', 'completed')->whereIn('shift_id', $shifts->pluck('id'))
            ->selectRaw('shift_id, COUNT(*) as count, SUM(total) as total')->groupBy('shift_id')->get()->keyBy('shift_id');

        $rows = $shifts->map(fn (PosShift $shift) => [
            'id' => $shift->id,
            'user_name' => $shift->user_name,
            'opened_at' => $this->clock->format($shift->opened_at, 'Y-m-d H:i'),
            'closed_at' => $shift->closed_at ? $this->clock->format($shift->closed_at, 'Y-m-d H:i') : null,
            'status' => $shift->status === 'open' ? 'Buka' : 'Tutup',
            'sales_count' => (int) ($sales[$shift->id]->count ?? 0),
            'sales_total' => round((float) ($sales[$shift->id]->total ?? 0), 2),
            'expected_cash' => $shift->expected_cash !== null ? (float) $shift->expected_cash : null,
            'counted_cash' => $shift->counted_cash !== null ? (float) $shift->counted_cash : null,
            'cash_difference' => $shift->cash_difference !== null ? (float) $shift->cash_difference : null,
        ])->all();

        return ['Rekap shift', [
            ['user_name', 'Kasir', 'text'],
            ['opened_at', 'Buka', 'datetime'],
            ['closed_at', 'Tutup', 'datetime'],
            ['status', 'Status', 'text'],
            ['sales_count', 'Transaksi', 'number'],
            ['sales_total', 'Penjualan', 'money'],
            ['expected_cash', 'Uang laci seharusnya', 'money'],
            ['counted_cash', 'Uang laci dihitung', 'money'],
            ['cash_difference', 'Selisih', 'money'],
        ], $rows, ['sales_count', 'sales_total', 'cash_difference']];
    }

    /**
     * Laba per produk = penjualan baris (setelah diskon baris, sebelum diskon
     * nota) dikurangi retur, dikurangi HPP saat jual (HPP barang retur yang
     * kembali ke stok dikembalikan).
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function productFigures(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        $lines = PosSaleLine::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.sale_id')
            ->where('pos_sales.status', 'completed')
            ->whereBetween('pos_sales.sold_at', [$from, $to])
            ->selectRaw('pos_sale_lines.product_id, MAX(pos_sale_lines.product_name) as product_name, SUM(pos_sale_lines.quantity) as quantity, SUM(pos_sale_lines.line_total) as revenue, SUM(pos_sale_lines.unit_cost * pos_sale_lines.quantity) as cost')
            ->groupBy('pos_sale_lines.product_id')
            ->get();
        foreach ($lines as $line) {
            $key = $line->product_id ?? 'x-'.$line->product_name;
            $rows[$key] = [
                'product_id' => $line->product_id ? (int) $line->product_id : null,
                'product_name' => $line->product_name,
                'quantity' => (float) $line->quantity,
                'revenue' => (float) $line->revenue,
                'cost' => (float) $line->cost,
            ];
        }

        $returns = PosSaleReturnLine::query()
            ->join('pos_sale_returns', 'pos_sale_returns.id', '=', 'pos_sale_return_lines.return_id')
            ->whereBetween('pos_sale_returns.returned_at', [$from, $to])
            ->selectRaw('pos_sale_return_lines.product_id, MAX(pos_sale_return_lines.product_name) as product_name, SUM(pos_sale_return_lines.quantity) as quantity, SUM(pos_sale_return_lines.amount) as amount, SUM(CASE WHEN pos_sale_return_lines.restock = ? THEN pos_sale_return_lines.unit_cost * pos_sale_return_lines.quantity ELSE 0 END) as cost', [true])
            ->groupBy('pos_sale_return_lines.product_id')
            ->get();
        foreach ($returns as $return) {
            $key = $return->product_id ?? 'x-'.$return->product_name;
            $rows[$key] ??= ['product_id' => $return->product_id ? (int) $return->product_id : null, 'product_name' => $return->product_name, 'quantity' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
            $rows[$key]['quantity'] -= (float) $return->quantity;
            $rows[$key]['revenue'] -= (float) $return->amount;
            $rows[$key]['cost'] -= (float) $return->cost;
        }

        foreach ($rows as &$row) {
            $row['quantity'] = round($row['quantity'], 3);
            $row['revenue'] = round($row['revenue'], 2);
            $row['cost'] = round($row['cost'], 2);
            $row['profit'] = round($row['revenue'] - $row['cost'], 2);
            $row['margin'] = $row['revenue'] > 0 ? round($row['profit'] / $row['revenue'] * 100, 1) : null;
        }
        unset($row);

        return $rows;
    }

    private function products(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->productFigures($from, $to);
        usort($rows, fn ($a, $b) => $b['profit'] <=> $a['profit']);

        return ['Laba per produk', [
            ['product_name', 'Produk', 'text'],
            ['quantity', 'Terjual', 'number'],
            ['revenue', 'Penjualan', 'money'],
            ['cost', 'Modal (HPP)', 'money'],
            ['profit', 'Laba kotor', 'money'],
            ['margin', 'Margin', 'percent'],
        ], array_map(fn ($row) => array_diff_key($row, ['product_id' => true]), $rows), ['revenue', 'cost', 'profit']];
    }

    private function categories(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $figures = $this->productFigures($from, $to);
        $productCategories = PosProduct::query()->whereIn('id', array_filter(array_column($figures, 'product_id')))->pluck('category_id', 'id');
        $names = PosCategory::query()->pluck('name', 'id');

        $rows = [];
        foreach ($figures as $figure) {
            $categoryId = $figure['product_id'] ? ($productCategories[$figure['product_id']] ?? null) : null;
            $key = $categoryId ?? 0;
            $rows[$key] ??= ['category_name' => $categoryId ? ($names[$categoryId] ?? 'Kategori #'.$categoryId) : 'Tanpa kategori', 'products' => 0, 'quantity' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
            $rows[$key]['products']++;
            $rows[$key]['quantity'] += $figure['quantity'];
            $rows[$key]['revenue'] += $figure['revenue'];
            $rows[$key]['cost'] += $figure['cost'];
        }
        foreach ($rows as &$row) {
            $row['quantity'] = round($row['quantity'], 3);
            $row['revenue'] = round($row['revenue'], 2);
            $row['cost'] = round($row['cost'], 2);
            $row['profit'] = round($row['revenue'] - $row['cost'], 2);
            $row['margin'] = $row['revenue'] > 0 ? round($row['profit'] / $row['revenue'] * 100, 1) : null;
        }
        unset($row);
        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue']);

        return ['Penjualan per kategori', [
            ['category_name', 'Kategori', 'text'],
            ['products', 'Produk', 'number'],
            ['quantity', 'Terjual', 'number'],
            ['revenue', 'Penjualan', 'money'],
            ['cost', 'Modal (HPP)', 'money'],
            ['profit', 'Laba kotor', 'money'],
            ['margin', 'Margin', 'percent'],
        ], $rows, ['revenue', 'cost', 'profit']];
    }

    private function hours(CarbonImmutable $from, CarbonImmutable $to): array
    {
        // Dikelompokkan di database menurut jam toko.
        $hourSql = $this->clock->localHourSql('sold_at');
        $rows = [];
        $grouped = PosSale::query()->where('status', 'completed')->whereBetween('sold_at', [$from, $to])
            ->selectRaw("{$hourSql} as hour, COUNT(*) as sales_count, SUM(total) as sales_total")
            ->groupByRaw($hourSql)
            ->get();
        foreach ($grouped as $group) {
            $hour = (int) $group->hour;
            $rows[$hour] = ['hour' => sprintf('%02d.00-%02d.59', $hour, $hour), 'sales_count' => (int) $group->sales_count, 'sales_total' => (float) $group->sales_total];
        }
        ksort($rows);
        foreach ($rows as &$row) {
            $row['sales_total'] = round($row['sales_total'], 2);
            $row['average'] = round($row['sales_total'] / $row['sales_count'], 2);
        }
        unset($row);

        return ['Penjualan per jam', [
            ['hour', 'Jam', 'text'],
            ['sales_count', 'Transaksi', 'number'],
            ['sales_total', 'Penjualan', 'money'],
            ['average', 'Rata-rata', 'money'],
        ], $rows, ['sales_count', 'sales_total']];
    }

    /**
     * Stok di bawah minimum, atau diperkirakan habis dalam 7 hari menurut
     * rata-rata jual 14 hari terakhir. Saran beli = cukup untuk 14 hari.
     */
    private function lowStock(): array
    {
        $since = $this->clock->startOfDay(CarbonImmutable::now($this->clock->timezone())->subDays(self::SALES_WINDOW_DAYS - 1)->toDateString());
        $sold = PosSaleLine::query()
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.sale_id')
            ->where('pos_sales.status', 'completed')
            ->where('pos_sales.sold_at', '>=', $since)
            ->whereNotNull('pos_sale_lines.product_id')
            ->selectRaw('pos_sale_lines.product_id, SUM(pos_sale_lines.quantity) as quantity')
            ->groupBy('pos_sale_lines.product_id')
            ->pluck('quantity', 'product_id');

        $rows = [];
        foreach (PosProduct::query()->where('track_stock', true)->where('is_active', true)->orderBy('name')->get() as $product) {
            $perDay = (float) ($sold[$product->id] ?? 0) / self::SALES_WINDOW_DAYS;
            $minimum = (float) ($product->min_stock ?? 0);
            $daysLeft = $perDay > 0 ? max(0, $product->stock) / $perDay : null;
            if ($product->stock > $minimum && ($daysLeft === null || $daysLeft > 7)) {
                continue;
            }
            $target = max($minimum, $perDay * self::SALES_WINDOW_DAYS);
            $rows[] = [
                'product_name' => $product->name,
                'unit' => $product->unit,
                'stock' => round($product->stock, 3),
                'min_stock' => $product->min_stock !== null ? round($minimum, 3) : null,
                'per_day' => round($perDay, 2),
                'days_left' => $daysLeft !== null ? round($daysLeft, 1) : null,
                'suggested' => max(0, ceil($target - max(0, $product->stock))),
            ];
        }
        usort($rows, fn ($a, $b) => ($a['days_left'] ?? ($a['stock'] <= 0 ? -1 : 999)) <=> ($b['days_left'] ?? ($b['stock'] <= 0 ? -1 : 999)));

        return ['Stok menipis & saran beli', [
            ['product_name', 'Produk', 'text'],
            ['unit', 'Satuan', 'text'],
            ['stock', 'Stok', 'number'],
            ['min_stock', 'Stok minimum', 'number'],
            ['per_day', 'Terjual/hari', 'number'],
            ['days_left', 'Habis dalam (hari)', 'number'],
            ['suggested', 'Saran beli', 'number'],
        ], $rows, []];
    }

    /**
     * Umur kasbon per pelanggan. Pembayaran dianggap melunasi kasbon paling
     * lama dulu, jadi sisa kasbon = transaksi kasbon terbaru sampai senilai saldo.
     */
    private function creditAging(): array
    {
        $today = CarbonImmutable::parse($this->clock->today());
        $customers = PosCustomer::query()->where('credit_balance', '>', 0)->orderByDesc('credit_balance')->get();

        // Satu query untuk semua pelanggan berkasbon (bukan satu per pelanggan),
        // terbaru dulu: sisa kasbon dianggap berasal dari transaksi kasbon
        // terbaru senilai saldo (FIFO).
        $state = [];
        foreach ($customers as $customer) {
            $state[$customer->id] = [
                'remaining' => (float) $customer->credit_balance,
                'buckets' => ['age_0_30' => 0.0, 'age_31_60' => 0.0, 'age_61_90' => 0.0, 'age_90' => 0.0],
                'oldest' => null,
            ];
        }

        $sales = PosSale::query()
            ->whereIn('customer_id', PosCustomer::query()->select('id')->where('credit_balance', '>', 0))
            ->where('status', 'completed')
            ->where('credit_amount', '>', 0)
            ->orderBy('customer_id')
            ->orderByDesc('sold_at')
            ->select(['customer_id', 'sold_at', 'credit_amount'])
            ->cursor();
        foreach ($sales as $sale) {
            $entry = &$state[$sale->customer_id];
            if ($entry['remaining'] > 0.004) {
                $portion = min($entry['remaining'], (float) $sale->credit_amount);
                $entry['remaining'] -= $portion;
                $date = CarbonImmutable::parse($this->clock->format($sale->sold_at, 'Y-m-d'));
                $entry['oldest'] = $date;
                $entry['buckets'][$this->bucket((int) $date->diffInDays($today))] += $portion;
            }
            unset($entry);
        }

        $rows = [];
        foreach ($customers as $customer) {
            $entry = $state[$customer->id];
            // Saldo tanpa transaksi asal (mis. data lama) dianggap paling tua.
            if ($entry['remaining'] > 0.004) {
                $entry['buckets']['age_90'] += $entry['remaining'];
            }
            $oldest = $entry['oldest'];
            $rows[] = [
                'customer_name' => $customer->name,
                'phone' => $customer->phone,
                'balance' => round((float) $customer->credit_balance, 2),
                'oldest_date' => $oldest?->toDateString(),
                'age_days' => $oldest ? (int) $oldest->diffInDays($today) : null,
            ] + array_map(fn ($value) => round($value, 2), $entry['buckets']);
        }

        return ['Umur kasbon', [
            ['customer_name', 'Pelanggan', 'text'],
            ['phone', 'Telepon', 'text'],
            ['balance', 'Sisa kasbon', 'money'],
            ['oldest_date', 'Kasbon tertua', 'date'],
            ['age_days', 'Umur (hari)', 'number'],
            ['age_0_30', '0-30 hari', 'money'],
            ['age_31_60', '31-60 hari', 'money'],
            ['age_61_90', '61-90 hari', 'money'],
            ['age_90', '> 90 hari', 'money'],
        ], $rows, ['balance', 'age_0_30', 'age_31_60', 'age_61_90', 'age_90']];
    }

    private function bucket(int $days): string
    {
        return match (true) {
            $days <= 30 => 'age_0_30',
            $days <= 60 => 'age_31_60',
            $days <= 90 => 'age_61_90',
            default => 'age_90',
        };
    }
}
