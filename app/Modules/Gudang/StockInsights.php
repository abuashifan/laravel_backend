<?php

namespace App\Modules\Gudang;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only inventory insights. Quantities are expressed in base-unit milliunits. */
class StockInsights
{
    public function report(array $options, WarehouseAccess $access): LengthAwarePaginator|Collection
    {
        $db = DB::connection('gudang');
        $now = now('Asia/Jakarta');
        $locations = $access->locations()->when($options['warehouse_id'] ?? null, fn ($q, $id) => $q->where('warehouse_id', $id));
        $warehouses = $access->warehouses()->when($options['warehouse_id'] ?? null, fn ($q, $id) => $q->where('id', $id));
        // A reversed issue no longer contributes demand. Transfers and adjustments are not sales.
        $outgoing = $db->table('gudang_stock_movements as m')->join('gudang_stock_documents as d', 'd.id', '=', 'm.document_id')
            ->join('gudang_locations as l', 'l.id', '=', 'm.location_id')
            ->whereIn('m.location_id', clone $locations)->where('d.type', 'issue')->where('d.status', '!=', 'reversed')
            ->where('m.quantity_milli', '<', 0);
        $recent = (clone $outgoing)->whereRaw('COALESCE(m.occurred_at, d.posted_at) >= ?', [$now->copy()->subDays(90)])
            ->groupBy('m.item_id', 'l.warehouse_id')->selectRaw('m.item_id, l.warehouse_id, -sum(m.quantity_milli) as outgoing_milli');
        $kind = $options['kind'];
        if ($kind === 'counts') {
            $accuracy = $db->table('gudang_count_lines as measured')
                ->whereIn('measured.location_id', clone $locations)
                ->whereRaw('COALESCE(measured.recount_milli, measured.counted_milli) IS NOT NULL')
                ->groupBy('measured.count_id', 'measured.location_id')
                ->selectRaw('measured.count_id, measured.location_id, count(*) as measured_lines, sum(CASE WHEN COALESCE(measured.recount_milli, measured.counted_milli) = measured.snapshot_milli THEN 1 ELSE 0 END) as accurate_lines');
            $query = $db->table('gudang_count_lines as line')->join('gudang_counts as count', 'count.id', '=', 'line.count_id')
                ->join('gudang_items as i', 'i.id', '=', 'line.item_id')->join('gudang_locations as l', 'l.id', '=', 'line.location_id')
                ->joinSub($accuracy, 'accuracy', fn ($join) => $join->on('accuracy.count_id', '=', 'line.count_id')->on('accuracy.location_id', '=', 'line.location_id'))
                ->whereIn('line.location_id', clone $locations)->where('count.status', 'approved')
                ->whereRaw('COALESCE(line.recount_milli, line.counted_milli) IS NOT NULL')
                ->select('count.id as count_id', 'count.status', 'count.cutoff_at', 'count.completed_at', 'i.id as item_id', 'i.sku', 'i.name', 'i.unit', 'l.id as location_id', 'l.warehouse_id', 'line.batch_id', 'line.counted_milli', 'line.recount_milli', 'line.counter_id', 'line.recount_by')
                ->selectRaw('COALESCE(line.recount_milli, line.counted_milli) - line.snapshot_milli as variance_milli')
                ->selectRaw('CASE WHEN COALESCE(line.recount_milli, line.counted_milli) = line.snapshot_milli THEN 1 ELSE 0 END as accurate')
                ->addSelect('accuracy.measured_lines', 'accuracy.accurate_lines')
                ->selectRaw('ROUND(100.0 * accuracy.accurate_lines / accuracy.measured_lines, 2) as location_accuracy_percent')
                ->orderByDesc('count.completed_at')->orderBy('line.id');
        } elseif ($kind === 'expiry') {
            $query = $db->table('gudang_batch_balances as b')->join('gudang_batches as lot', 'lot.id', '=', 'b.batch_id')
                ->join('gudang_items as i', 'i.id', '=', 'lot.item_id')->join('gudang_locations as l', 'l.id', '=', 'b.location_id')
                ->whereIn('b.location_id', clone $locations)->where('b.quantity_milli', '>', 0)->whereNotNull('lot.expires_at')
                ->whereDate('lot.expires_at', '<=', $now->copy()->addDays($options['days'] ?? 30)->toDateString())
                ->select('i.id as item_id', 'i.sku', 'i.name', 'i.unit', 'lot.id as batch_id', 'lot.code as batch_code', 'lot.status', 'lot.expires_at', 'l.id as location_id', 'l.warehouse_id', 'b.quantity_milli')
                ->orderBy('lot.expires_at')->orderBy('lot.id');
        } elseif ($kind === 'abc') {
            $totals = $db->query()->fromSub($recent, 'r')->groupBy('item_id')->selectRaw('item_id, sum(outgoing_milli) as outgoing_milli');
            $query = $db->table('gudang_items as i')->leftJoinSub($totals, 'r', 'r.item_id', '=', 'i.id')
                ->whereExists(clone $locations)->selectRaw('i.id as item_id, i.sku, i.name, i.unit, i.abc_override, COALESCE(r.outgoing_milli, 0) as outgoing_milli')
                ->orderByDesc('outgoing_milli')->orderBy('i.id');
        } else {
            $balances = $db->table('gudang_stock_balances as b')->join('gudang_locations as l', 'l.id', '=', 'b.location_id')
                ->join('gudang_items as item', 'item.id', '=', 'b.item_id')->whereIn('b.location_id', clone $locations)->whereIn('l.kind', ['simpan', 'terima'])
                ->where('item.batch_enabled', false)->groupBy('b.item_id', 'l.warehouse_id')
                ->selectRaw('b.item_id, l.warehouse_id, sum(b.quantity_milli) as quantity_milli');
            $lots = $db->table('gudang_batch_balances as b')->join('gudang_batches as lot', 'lot.id', '=', 'b.batch_id')
                ->join('gudang_locations as l', 'l.id', '=', 'b.location_id')->whereIn('b.location_id', clone $locations)
                ->whereIn('l.kind', ['simpan', 'terima'])->where('lot.status', 'active')
                ->where(fn ($q) => $q->whereNull('lot.expires_at')->orWhereDate('lot.expires_at', '>=', $now->toDateString()))
                ->groupBy('lot.item_id', 'l.warehouse_id')->selectRaw('lot.item_id, l.warehouse_id, sum(b.quantity_milli) as quantity_milli');
            if ($kind === 'aging') {
                // Physical stock includes restricted and expired inventory. The first recorded
                // receipt is a proxy for age, not a FIFO age for the units still on hand.
                $stock = $db->table('gudang_stock_balances as physical')
                    ->join('gudang_locations as physical_location', 'physical_location.id', '=', 'physical.location_id')
                    ->whereIn('physical.location_id', clone $locations)->groupBy('physical.item_id', 'physical_location.warehouse_id')
                    ->selectRaw('physical.item_id, physical_location.warehouse_id, sum(physical.quantity_milli) as quantity_milli');
            } else {
                $stock = $db->query()->fromSub($balances->unionAll($lots), 's')->groupBy('item_id', 'warehouse_id')
                    ->selectRaw('item_id, warehouse_id, sum(quantity_milli) as quantity_milli');
            }
            $query = $db->table('gudang_items as i')->crossJoin('gudang_warehouses as w')->whereIn('w.id', $warehouses)
                ->leftJoinSub($stock, 's', fn ($join) => $join->on('s.item_id', '=', 'i.id')->on('s.warehouse_id', '=', 'w.id'))
                ->leftJoinSub($recent, 'r', fn ($join) => $join->on('r.item_id', '=', 'i.id')->on('r.warehouse_id', '=', 'w.id'))
                ->leftJoin('gudang_stock_rules as rule', fn ($join) => $join->on('rule.item_id', '=', 'i.id')->on('rule.warehouse_id', '=', 'w.id'))
                ->selectRaw('i.id as item_id, i.sku, i.name, i.unit, w.id as warehouse_id, w.name as warehouse, COALESCE(s.quantity_milli, 0) as quantity_milli, COALESCE(r.outgoing_milli, 0) as outgoing_milli, i.lead_days, i.safety_milli, COALESCE(rule.minimum_milli, i.minimum_milli) as minimum_milli, rule.max_milli, rule.reorder_milli');
            if ($kind === 'aging') {
                $last = (clone $outgoing)->groupBy('m.item_id', 'l.warehouse_id')->selectRaw('m.item_id, l.warehouse_id, max(COALESCE(m.occurred_at, d.posted_at)) as last_out_at');
                $firstIn = $db->table('gudang_stock_movements as incoming')->join('gudang_stock_documents as incoming_doc', 'incoming_doc.id', '=', 'incoming.document_id')
                    ->join('gudang_locations as incoming_location', 'incoming_location.id', '=', 'incoming.location_id')
                    ->whereIn('incoming.location_id', clone $locations)->where('incoming.quantity_milli', '>', 0)
                    ->groupBy('incoming.item_id', 'incoming_location.warehouse_id')
                    ->selectRaw('incoming.item_id, incoming_location.warehouse_id, min(COALESCE(incoming.occurred_at, incoming_doc.posted_at)) as first_in_at');
                $query->leftJoinSub($last, 'last_out', fn ($join) => $join->on('last_out.item_id', '=', 'i.id')->on('last_out.warehouse_id', '=', 'w.id'))
                    ->leftJoinSub($firstIn, 'first_in', fn ($join) => $join->on('first_in.item_id', '=', 'i.id')->on('first_in.warehouse_id', '=', 'w.id'))
                    ->addSelect('last_out.last_out_at', 'first_in.first_in_at')->where('s.quantity_milli', '>', 0)
                    ->where(fn ($q) => $q->whereRaw('COALESCE(last_out.last_out_at, first_in.first_in_at) < ?', [$now->copy()->subDays(30)])
                        ->orWhere(fn ($missing) => $missing->whereNull('last_out.last_out_at')->whereNull('first_in.first_in_at')));
            }
            $query->orderBy('i.sku')->orderBy('w.id');
        }
        if ($kind !== 'abc' && ($search = trim($options['q'] ?? '')) !== '') {
            $query->where(fn ($q) => $q->whereLike('i.sku', '%'.$search.'%')->orWhereLike('i.name', '%'.$search.'%'));
        }
        // ABC classification is calculated over the full selection, before pagination and search.
        if ($kind === 'abc') {
            $rows = $query->get();
            $total = $rows->sum('outgoing_milli');
            $cumulative = 0;
            $rows->transform(function ($row) use (&$cumulative, $total, $now) {
                $ratio = $total ? $cumulative / $total : 1;
                $row->calculated_class = $ratio < 0.8 ? 'A' : ($ratio < 0.95 ? 'B' : 'C');
                $row->abc_class = $row->abc_override ?: $row->calculated_class;
                $cumulative += (int) $row->outgoing_milli;
                $row->calculated_at = $now->toIso8601String();

                return $row;
            });
            $search = mb_strtolower(trim($options['q'] ?? ''));
            if ($search !== '') {
                $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower($row->sku), $search) || str_contains(mb_strtolower($row->name), $search))->values();
            }
            $page = $options['page'] ?? 1;
            if (! empty($options['all'])) {
                return $rows;
            }

            return new LengthAwarePaginator($rows->forPage($page, 50)->values(), $rows->count(), 50, $page, ['path' => request()->url(), 'query' => request()->query()]);
        }
        $page = empty($options['all']) ? $query->paginate(50) : null;
        $rows = $page?->getCollection() ?? $query->get();
        $rows->transform(function ($row) use ($kind, $now) {
            $row->calculated_at = $now->toIso8601String();
            if ($kind === 'replenishment') {
                $need = (int) ceil((int) $row->outgoing_milli / 90 * (int) $row->lead_days + (int) $row->safety_milli);
                $row->demand_target_milli = $need;
                $trigger = max($need, (int) $row->minimum_milli, (int) $row->reorder_milli);
                $row->reorder_needed = (int) $row->quantity_milli < 0 || (($trigger > 0 || (int) $row->max_milli > 0) && (int) $row->quantity_milli <= $trigger);
                $row->suggested_milli = $row->reorder_needed ? max(0, max($need, (int) $row->max_milli, (int) $row->minimum_milli, (int) $row->reorder_milli) - (int) $row->quantity_milli) : 0;
            } elseif ($kind === 'aging') {
                $row->aging_basis = $row->last_out_at ? 'last_outgoing' : ($row->first_in_at ? 'first_incoming_proxy' : 'unknown');
                $basis = $row->last_out_at ?? $row->first_in_at;
                $row->aging_class = $basis === null ? 'unknown' : (Carbon::parse($basis)->lt($now->copy()->subDays(90)) ? 'dead' : 'slow');
            } elseif ($kind === 'expiry') {
                $row->expired = $row->expires_at < $now->toDateString();
            }

            return $row;
        });

        return $page ?? $rows;
    }
}
