<?php

namespace App\Modules\Gudang;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OutboundOrders
{
    private const PICKABLE = ['simpan', 'terima'];

    public function __construct(
        private WarehouseAccess $access,
        private WarehouseDocuments $documents,
        private StockUnits $units,
    ) {}

    public function reserve(array $input, int $actor): object
    {
        $db = DB::connection('gudang');
        $fixedLocation = isset($input['location_id']) ? (int) $input['location_id'] : null;
        $locations = $db->table('gudang_locations')->whereIn('kind', self::PICKABLE);
        if ($fixedLocation !== null) {
            $this->access->requireLocations([$fixedLocation]);
            $locations->where('id', $fixedLocation);
        } else {
            $warehouse = (int) $input['warehouse_id'];
            abort_unless($this->access->warehouses()->where('id', $warehouse)->exists(), 403);
            $locations->where('warehouse_id', $warehouse);
        }
        $ids = $locations->orderBy('id')->pluck('id');
        $this->check($ids->isNotEmpty(), 'Gudang belum memiliki lokasi simpan atau terima.');

        $lines = collect($input['lines'])->keyBy('item_id')->sortKeys();
        $amounts = $lines->map(fn (array $line) => StockLedger::milli($line['quantity']))->all();
        $units = $lines->filter(fn (array $line) => isset($line['unit_id']))
            ->map(fn (array $line) => (int) $line['unit_id'])->all();
        $scope = $fixedLocation ?? ['warehouse' => (int) $input['warehouse_id']];
        $signature = [$actor, $scope, $input['destination'], $amounts];
        if ($units !== []) {
            $signature[] = $units;
        }
        $hash = $this->hash($signature);

        return $db->transaction(function () use ($db, $input, $actor, $ids, $lines, $units, $hash) {
            $orders = $db->table('gudang_outbound_orders');
            $created = $orders->insertOrIgnore([
                'location_id' => $ids->first(), 'destination' => $input['destination'],
                'request_key' => $input['request_key'], 'payload_hash' => $hash, 'user_id' => $actor, 'created_at' => now(),
            ]);
            $order = $orders->where('request_key', $input['request_key'])->firstOrFail();
            $this->check(hash_equals($order->payload_hash, $hash), 'Kunci simpan sudah digunakan untuk pesanan lain.');
            if (! $created) {
                return $order;
            }

            $allocationCount = 0;
            foreach ($lines as $id => $line) {
                $item = $db->table('gudang_items')->where('id', $id)->lockForUpdate()->firstOrFail();
                $converted = $this->units->convert($id, $line['quantity'], $units[$id] ?? null);
                $remaining = $converted['quantity_milli'];
                foreach ($this->candidates($db, $item, $ids) as $candidate) {
                    $take = min($remaining, (int) $candidate->available_milli);
                    $allocation = [
                        'order_id' => $order->id, 'item_id' => $id, 'location_id' => $candidate->location_id,
                        'batch_id' => $item->batch_enabled ? $candidate->batch_id : null,
                    ];
                    $this->check(++$allocationCount <= 200, 'Pesanan melebihi 200 alokasi lokasi dan batch. Bagi pesanan menjadi dua.');
                    $this->reserveBalance($db, (object) $allocation, $take);
                    $db->table('gudang_outbound_allocations')->insert($allocation + ['quantity_milli' => $take]);
                    $remaining -= $take;
                    if ($remaining === 0) {
                        break;
                    }
                }
                $this->check($remaining === 0, "Stok tersedia {$item->sku} tidak mencukupi.");
                $db->table('gudang_outbound_lines')->insert([
                    'order_id' => $order->id, 'item_id' => $id, 'unit' => $item->unit,
                ] + $converted);
            }

            return $order;
        }, 3);
    }

    private function candidates(Connection $db, object $item, Collection $locations): Collection
    {
        if ($item->batch_enabled) {
            $balances = $db->table('gudang_batch_balances as b')->join('gudang_batches as lot', 'lot.id', '=', 'b.batch_id')
                ->where('lot.item_id', $item->id)->whereIn('b.location_id', $locations)
                ->whereColumn('b.quantity_milli', '>', 'b.reserved_milli');

            return BatchStock::saleable($balances, 'lot')
                ->orderByRaw('case when lot.expires_at is null then 1 else 0 end')
                ->orderBy('lot.expires_at')->orderBy('lot.id')->orderBy('b.location_id')
                ->select('b.location_id', 'b.batch_id')->selectRaw('b.quantity_milli - b.reserved_milli as available_milli')
                ->limit(201)->get();
        }

        return $db->table('gudang_stock_balances as b')->join('gudang_locations as l', 'l.id', '=', 'b.location_id')
            ->where('b.item_id', $item->id)->whereIn('b.location_id', $locations)
            ->whereColumn('b.quantity_milli', '>', 'b.reserved_milli')
            ->orderByRaw("case when l.kind = 'simpan' then 0 else 1 end")->orderBy('l.name')->orderBy('l.id')
            ->select('b.location_id')->selectRaw('b.quantity_milli - b.reserved_milli as available_milli')
            ->limit(201)->get();
    }

    public function act(int $id, array $input, int $actor): object
    {
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $id, $input, $actor) {
            $orders = $db->table('gudang_outbound_orders')->where('id', $id)->whereIn('location_id', $this->access->locations());
            $order = (clone $orders)->lockForUpdate()->firstOrFail();
            $action = $input['action'];
            $note = $input['note'] ?? null;
            $hash = $this->hash([$actor, $action, $input['run_id'] ?? null, $input['lines'] ?? [], $input['packages'] ?? [], $note]);
            $actions = $db->table('gudang_outbound_actions');
            $previous = (clone $actions)->where('order_id', $id)->where('request_key', $input['request_key'])->first();
            if ($previous) {
                $this->check(hash_equals($previous->payload_hash, $hash), 'Kunci aksi sudah digunakan untuk tindakan lain.');

                return $order;
            }
            $this->check(in_array($order->status, ['reserved', 'partial'], true), 'Pesanan sudah ditutup.');

            $runs = $db->table('gudang_outbound_runs')->where('order_id', $id);
            $active = (clone $runs)->whereIn('status', ['picked', 'packed'])->first();
            if (in_array($action, ['pack', 'ship', 'reset'], true)) {
                $this->check($active !== null && (int) $active->id === (int) $input['run_id'], 'Sesi ambil atau kemas sudah berubah. Muat ulang pesanan.');
            }

            $runId = $active?->id;
            if ($action === 'pick') {
                $this->check($active === null, 'Selesaikan sesi ambil atau kemas yang masih aktif.');
                $runId = $this->pick($db, $id, $input['lines']);
            } elseif ($action === 'pack') {
                $this->check($active->status === 'picked', 'Pesanan sudah dikemas.');
                foreach ($input['packages'] as $index => $package) {
                    $db->table('gudang_outbound_packages')->insert([
                        'run_id' => $runId, 'number' => $index + 1,
                        'weight_grams' => $package['weight_grams'], 'note' => $package['note'] ?? null,
                    ]);
                }
                (clone $runs)->where('id', $runId)->update(['status' => 'packed']);
            } elseif ($action === 'ship') {
                $this->check($active->status === 'packed', 'Kemas barang sebelum mencatat pengiriman.');
                $this->ship($db, $order, $runId, $input['request_key'], $actor);
                (clone $runs)->where('id', $runId)->update(['status' => 'shipped']);
                $order->status = $this->outstanding($db, $id)->exists() ? 'partial' : 'shipped';
            } elseif ($action === 'reset') {
                (clone $runs)->where('id', $runId)->update(['status' => 'reset']);
            } else {
                $this->check($active === null, 'Batalkan sesi ambil atau kemas dan kembalikan barang sebelum melepas pesanan.');
                foreach ($this->outstanding($db, $id)->orderBy('item_id')->orderBy('id')->get() as $allocation) {
                    $this->lockItem($db, $allocation->item_id);
                    $this->reserveBalance($db, $allocation, -$this->remaining($allocation));
                }
                $order->status = 'cancelled';
            }
            $orders->update(['status' => $order->status]);
            $actions->insert([
                'order_id' => $id, 'run_id' => $runId, 'action' => $action,
                'request_key' => $input['request_key'], 'payload_hash' => $hash, 'note' => $note,
                'user_id' => $actor, 'created_at' => now(),
            ]);

            return $order;
        }, 3);
    }

    private function pick(Connection $db, int $order, array $input): int
    {
        $pending = $this->outstanding($db, $order)->get()->keyBy('id');
        $selected = collect($input)->keyBy('allocation_id');
        $this->check($pending->isNotEmpty() && $pending->keys()->sort()->values()->all() === $selected->keys()->sort()->values()->all(), 'Isi hasil ambil untuk semua baris yang masih dipesan.');
        $locations = $db->table('gudang_locations')->whereIn('id', $pending->pluck('location_id'))->get()->keyBy('id');
        $items = $db->table('gudang_items')->whereIn('id', $pending->pluck('item_id'))->get()->keyBy('id');
        $barcodes = $db->table('gudang_barcodes')->whereIn('item_id', $items->keys())->get()->groupBy('item_id');
        $batches = $db->table('gudang_batches as lot')->whereIn('lot.id', $pending->pluck('batch_id')->filter());
        $eligible = BatchStock::saleable($batches, 'lot')->pluck('code', 'id');
        $rows = [];
        $hasPicked = false;
        foreach ($pending as $id => $allocation) {
            $line = $selected[$id];
            $item = $items[$allocation->item_id];
            $location = $locations[$allocation->location_id];
            $amount = StockLedger::milli($line['quantity']);
            $planned = $this->remaining($allocation);
            $this->check($amount <= $planned, "Jumlah diambil untuk {$item->sku} melebihi sisa.");
            $reason = $line['reason'] ?? null;
            $this->check($amount === $planned || trim((string) $reason) !== '', "Isi alasan kekurangan {$item->sku}.");
            if ($amount > 0) {
                $this->check(in_array($line['location_code'] ?? '', [$location->barcode, 'LOC-'.$location->id], true), "Kode rak {$location->name} tidak sesuai.");
                $itemCode = mb_strtoupper($line['item_code'] ?? '');
                $this->check($itemCode === $item->sku || $barcodes->get($item->id, collect())->pluck('code')->containsStrict($itemCode), "Kode barang {$item->sku} tidak sesuai.");
                if ($allocation->batch_id !== null) {
                    $code = $eligible->get($allocation->batch_id);
                    $this->check($code !== null && ($line['batch_code'] ?? '') === $code, "Kode batch {$item->sku} tidak sesuai atau batch tidak layak.");
                }
                $hasPicked = true;
            }
            $rows[] = [
                'allocation_id' => $id, 'planned_milli' => $planned, 'picked_milli' => $amount,
                'reason' => $amount < $planned ? $reason : null,
                'location_code' => $amount > 0 ? $line['location_code'] : null,
                'item_code' => $amount > 0 ? $line['item_code'] : null,
                'batch_code' => $amount > 0 && $allocation->batch_id !== null ? $line['batch_code'] : null,
            ];
        }
        $run = $db->table('gudang_outbound_runs')->insertGetId([
            'order_id' => $order, 'status' => $hasPicked ? 'picked' : 'short', 'created_at' => now(),
        ]);
        foreach (array_chunk($rows, 80) as $chunk) {
            $db->table('gudang_outbound_picks')->insert(array_map(fn ($row) => ['run_id' => $run] + $row, $chunk));
        }

        return $run;
    }

    private function ship(Connection $db, object $order, int $run, string $requestKey, int $actor): void
    {
        $picks = $db->table('gudang_outbound_picks as p')->join('gudang_outbound_allocations as a', 'a.id', '=', 'p.allocation_id')
            ->where('p.run_id', $run)->where('a.order_id', $order->id)->where('p.picked_milli', '>', 0)
            ->orderBy('a.item_id')->orderBy('a.id')->get(['a.*', 'p.picked_milli']);
        $this->check($picks->isNotEmpty(), 'Tidak ada barang yang sudah diambil.');
        $this->access->requireLocations($picks->pluck('location_id')->all());
        foreach ($picks as $allocation) {
            $amount = (int) $allocation->picked_milli;
            $this->check($amount <= $this->remaining($allocation), 'Jumlah kirim melebihi sisa pesanan.');
            $this->lockItem($db, $allocation->item_id);
            $this->reserveBalance($db, $allocation, -$amount);
            $db->table('gudang_outbound_allocations')->where('id', $allocation->id)->increment('shipped_milli', $amount);
            $db->table('gudang_outbound_lines')->where(['order_id' => $order->id, 'item_id' => $allocation->item_id])
                ->increment('shipped_milli', $amount);
        }

        foreach ($picks->groupBy('location_id')->sortKeys() as $location => $allocated) {
            $amounts = [];
            $batches = [];
            foreach ($allocated as $allocation) {
                $item = (int) $allocation->item_id;
                $amounts[$item] = ($amounts[$item] ?? 0) + (int) $allocation->picked_milli;
                if ($allocation->batch_id !== null) {
                    $batches[$item][] = ['batch_id' => (int) $allocation->batch_id, 'quantity_milli' => -(int) $allocation->picked_milli];
                }
            }
            $lines = [];
            foreach ($amounts as $item => $amount) {
                $lines[] = ['item_id' => $item, 'quantity' => StockLedger::decimal($amount)];
            }
            $key = hash('sha256', "outbound:{$order->id}:{$location}:{$requestKey}");
            $doc = $this->documents->draft([
                'type' => 'issue', 'request_key' => 'outbound-draft-'.$key, 'source' => $order->destination,
                'location_id' => $location, 'document_date' => now('Asia/Jakarta')->toDateString(), 'lines' => $lines,
            ], $actor);
            $this->documents->act($doc->id, ['action' => 'post', 'request_key' => 'outbound-ship-'.$key], $actor, $batches);
            $db->table('gudang_outbound_ship_docs')->insert(['run_id' => $run, 'document_id' => $doc->id]);
        }
    }

    private function reserveBalance(Connection $db, object $allocation, int $change): void
    {
        $constraints = ['location_id' => $allocation->location_id];
        $balances = [$db->table('gudang_stock_balances')->where($constraints + ['item_id' => $allocation->item_id])];
        if ($allocation->batch_id !== null) {
            $balances[] = $db->table('gudang_batch_balances')->where($constraints + ['batch_id' => $allocation->batch_id]);
        }
        foreach ($balances as $balance) {
            if ($change > 0) {
                $balance->whereRaw('quantity_milli - reserved_milli >= ?', [$change]);
            } else {
                $balance->where('reserved_milli', '>=', -$change);
            }
            $this->check($balance->increment('reserved_milli', $change) === 1, 'Stok tersedia berubah. Muat ulang pesanan atau pilih jumlah lain.');
        }
    }

    private function outstanding(Connection $db, int $id): Builder
    {
        return $db->table('gudang_outbound_allocations')->where('order_id', $id)->whereColumn('quantity_milli', '>', 'shipped_milli');
    }

    private function remaining(object $allocation): int
    {
        return (int) $allocation->quantity_milli - (int) $allocation->shipped_milli;
    }

    private function lockItem(Connection $db, int $id): void
    {
        $db->table('gudang_items')->where('id', $id)->lockForUpdate()->firstOrFail();
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['order' => $message]);
        }
    }
}
