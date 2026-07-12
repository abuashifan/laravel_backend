<?php

namespace App\Modules\Inventory\Services\Reports;

use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Umur Persediaan (Fase 8 T8.1).
 *
 * Metode aging (didokumentasikan): Finlite memakai average cost — TIDAK ada lot/layer FIFO,
 * sehingga umur tidak dapat dihitung per-lot. Pendekatan yang dipakai:
 *  - Basis = saldo stok saat ini (stock_balances), on-hand qty & value.
 *  - Umur tiap baris = selisih hari antara `as_of_date` dengan tanggal penerimaan (inbound)
 *    terakhir yang posted untuk produk+gudang tsb (fallback: last_movement_at, lalu as_of).
 *  - Seluruh on-hand value baris dimasukkan ke SATU bucket sesuai umur tsb
 *    (0-30, 31-60, 61-90, >90 hari).
 * `as_of_date` hanyalah titik acuan umur; on-hand tetap posisi saat ini.
 */
class InventoryAgingReportService
{
    private const BUCKET_KEYS = ['0_30', '31_60', '61_90', 'over_90'];

    /**
     * @param  array<string,mixed>  $filters
     * @return array{as_of_date:string, filters:array<string,mixed>, rows:list<array<string,mixed>>, totals:array<string,mixed>}
     */
    public function report(array $filters = []): array
    {
        $asOf = ! empty($filters['as_of_date'])
            ? Carbon::parse((string) $filters['as_of_date'])->startOfDay()
            : Carbon::today();

        $query = StockBalance::query()->with(['product', 'warehouse']);
        $this->applyFilters($query, $filters);

        $balances = $query->get();

        $lastInDates = $this->lastInboundDates($filters, $asOf);

        $rows = [];
        foreach ($balances as $b) {
            $productId = (int) $b->product_id;
            $warehouseId = (int) $b->warehouse_id;
            $key = $productId.'|'.$warehouseId;

            $anchor = $lastInDates[$key] ?? null;
            if ($anchor === null && $b->last_movement_at !== null) {
                $anchor = Carbon::parse($b->last_movement_at)->startOfDay();
            }

            $ageDays = $anchor !== null ? max(0, $anchor->diffInDays($asOf, false)) : 0;
            $bucket = $this->bucketFor($ageDays);

            $qty = (float) $b->quantity_on_hand;
            $value = (float) $b->total_value;

            $buckets = array_fill_keys(self::BUCKET_KEYS, 0.0);
            $buckets[$bucket] = $value;

            $rows[] = [
                'product_id' => $productId,
                'product_code' => $b->product?->product_code,
                'product_name' => $b->product?->product_name,
                'warehouse_id' => $warehouseId,
                'warehouse_code' => $b->warehouse?->code,
                'warehouse_name' => $b->warehouse?->name,
                'quantity_on_hand' => $qty,
                'average_cost' => (float) $b->average_cost,
                'total_value' => $value,
                'last_inbound_date' => $anchor?->toDateString(),
                'age_days' => $ageDays,
                'buckets' => $buckets,
            ];
        }

        usort($rows, fn ($a, $b) => $b['total_value'] <=> $a['total_value']);

        return [
            'as_of_date' => $asOf->toDateString(),
            'filters' => $filters,
            'rows' => $rows,
            'totals' => $this->totals($rows),
        ];
    }

    /**
     * Tanggal inbound (direction=in) posted terakhir per produk+gudang, s/d as_of_date.
     *
     * @param  array<string,mixed>  $filters
     * @return array<string,Carbon>
     */
    private function lastInboundDates(array $filters, Carbon $asOf): array
    {
        $q = DB::connection('tenant')->table('stock_movement_lines as sml')
            ->join('stock_movements as sm', 'sm.id', '=', 'sml.stock_movement_id')
            ->where('sm.status', '=', 'posted')
            ->where('sml.direction', '=', 'in')
            ->whereDate('sm.movement_date', '<=', $asOf->toDateString());

        if (! empty($filters['product_id'])) {
            $q->where('sml.product_id', '=', (int) $filters['product_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $q->where('sml.warehouse_id', '=', (int) $filters['warehouse_id']);
        }

        $rows = $q->groupBy('sml.product_id', 'sml.warehouse_id')
            ->select([
                'sml.product_id',
                'sml.warehouse_id',
                DB::raw('MAX(sm.movement_date) as last_in'),
            ])
            ->get();

        $out = [];
        foreach ($rows as $r) {
            if ($r->last_in === null) {
                continue;
            }
            $out[((int) $r->product_id).'|'.((int) $r->warehouse_id)] = Carbon::parse($r->last_in)->startOfDay();
        }

        return $out;
    }

    private function bucketFor(int $ageDays): string
    {
        if ($ageDays <= 30) {
            return '0_30';
        }
        if ($ageDays <= 60) {
            return '31_60';
        }
        if ($ageDays <= 90) {
            return '61_90';
        }

        return 'over_90';
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['product_id'])) {
            $query->where('product_id', (int) $filters['product_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }
        if (! empty($filters['category_id'])) {
            $catId = (int) $filters['category_id'];
            $query->whereHas('product', fn ($q) => $q->where('category_id', $catId));
        }

        $includeZero = (bool) ($filters['include_zero'] ?? false);
        $includeNegative = (bool) ($filters['include_negative'] ?? false);
        if (! $includeZero) {
            $query->where('quantity_on_hand', '!=', 0);
        }
        if (! $includeNegative) {
            $query->where('quantity_on_hand', '>=', 0);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function totals(array $rows): array
    {
        $qty = 0.0;
        $value = 0.0;
        $buckets = array_fill_keys(self::BUCKET_KEYS, 0.0);

        foreach ($rows as $r) {
            $qty += (float) $r['quantity_on_hand'];
            $value += (float) $r['total_value'];
            foreach (self::BUCKET_KEYS as $k) {
                $buckets[$k] += (float) $r['buckets'][$k];
            }
        }

        return [
            'total_quantity_on_hand' => $qty,
            'total_value' => round($value, (int) config('inventory.amount_precision', 2)),
            'buckets' => $buckets,
        ];
    }
}
