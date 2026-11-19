<?php

namespace App\Modules\Gudang;

use App\Shared\Permission\PermissionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockCounts
{
    public function __construct(private WarehouseAccess $access, private PermissionService $permissions, private StockLedger $ledger) {}

    private function permit(string $permission): void
    {
        abort_unless($this->permissions->can('gudang.counts.view') && $this->permissions->can('gudang.counts.'.$permission), 403);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function hash(array $input, int $actor): string
    {
        return hash('sha256', json_encode([$actor, $input], JSON_THROW_ON_ERROR));
    }

    private function scoped(int $id, bool $lock = false): object
    {
        $db = DB::connection('gudang');
        $query = $db->table('gudang_counts')->where('id', $id);
        $count = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($count, 404);
        $this->access->requireLocations($db->table('gudang_count_locations')->where('count_id', $id)->pluck('location_id')->all());

        return $count;
    }

    public function listing()
    {
        $this->permit('view');
        $db = DB::connection('gudang');

        return $db->table('gudang_counts as c')->whereNotIn('c.id', $db->table('gudang_count_locations')->select('count_id')->whereNotIn('location_id', $this->access->locations()))
            ->whereIn('c.id', $db->table('gudang_count_locations')->select('count_id')->whereIn('location_id', $this->access->locations()))
            ->orderByDesc('c.id')->paginate(30);
    }

    public function show(int $id): object
    {
        $this->permit('view');
        $db = DB::connection('gudang');
        $count = $this->scoped($id);
        $count->location_ids = $db->table('gudang_count_locations')->where('count_id', $id)->pluck('location_id');
        $count->lines = $db->table('gudang_count_lines as x')->join('gudang_items as i', 'i.id', '=', 'x.item_id')
            ->join('gudang_locations as l', 'l.id', '=', 'x.location_id')
            ->leftJoin('gudang_batches as b', 'b.id', '=', 'x.batch_id')->where('x.count_id', $id)->orderBy('x.id')
            ->get(['x.*', 'i.sku', 'i.name', 'i.unit', 'b.code as batch_code', 'l.name as location_name']);
        $blind = in_array($count->status, ['draft', 'counting'], true) && ! $this->permissions->can('gudang.counts.approve');
        foreach ($count->lines as $line) {
            $line->recount_required = $line->counted_milli !== null && $line->recount_milli === null
                && abs($line->counted_milli - $line->snapshot_milli) > max(1000, abs($line->snapshot_milli) * 0.1);
            if ($blind) {
                unset($line->snapshot_milli);
            } else {
                $line->delta_milli = $line->counted_milli === null ? null : ($line->recount_milli ?? $line->counted_milli) - $line->snapshot_milli;
            }
        }
        $count->actions = $db->table('gudang_count_actions')->where('count_id', $id)->orderBy('id')->get(['action', 'user_id', 'note', 'created_at']);

        return $count;
    }

    /** Calculate scheduling suggestions on demand, scoped to the caller's warehouses. */
    public function due(): array
    {
        abort_unless($this->permissions->can('gudang.counts.view') || $this->permissions->can('gudang.counts.create'), 403);
        $db = DB::connection('gudang');
        $now = now('Asia/Jakarta');
        $warehouses = $db->table('gudang_warehouses')->whereIn('id', $this->access->warehouses())->orderBy('id')->get();
        $outgoing = $db->table('gudang_stock_movements as m')
            ->join('gudang_stock_documents as d', 'd.id', '=', 'm.document_id')
            ->whereIn('m.location_id', $this->access->locations())->where('d.type', 'issue')->where('d.status', '!=', 'reversed')
            ->where('m.quantity_milli', '<', 0)->whereRaw('COALESCE(m.occurred_at, d.posted_at) >= ?', [$now->copy()->subDays(90)])
            ->groupBy('m.item_id')->selectRaw('m.item_id, -sum(m.quantity_milli) as outgoing_milli');
        $items = $db->table('gudang_items as i')->leftJoinSub($outgoing, 'o', 'o.item_id', '=', 'i.id')
            ->selectRaw('i.id as item_id, i.sku, i.name, i.abc_override, COALESCE(o.outgoing_milli, 0) as outgoing_milli')
            ->orderByDesc('outgoing_milli')->orderBy('i.id')->get();
        $total = $items->sum('outgoing_milli');
        $cumulative = 0;
        $last = $db->table('gudang_count_lines as line')->join('gudang_counts as c', 'c.id', '=', 'line.count_id')
            ->join('gudang_locations as l', 'l.id', '=', 'line.location_id')
            ->whereIn('l.id', $this->access->locations())->where('c.status', 'approved')
            ->groupBy('line.item_id', 'l.warehouse_id')->selectRaw('line.item_id, l.warehouse_id, max(c.completed_at) as last_count_at')->get()
            ->keyBy(fn ($row) => $row->item_id.':'.$row->warehouse_id);
        $rows = [];
        foreach ($items as $item) {
            $ratio = $total ? $cumulative / $total : 1;
            $class = $item->abc_override ?: ($ratio < 0.8 ? 'A' : ($ratio < 0.95 ? 'B' : 'C'));
            $cumulative += (int) $item->outgoing_milli;
            $interval = ['A' => 7, 'B' => 30, 'C' => 90][$class];
            foreach ($warehouses as $warehouse) {
                $previous = $last->get($item->item_id.':'.$warehouse->id)?->last_count_at;
                $next = $previous ? Carbon::parse($previous)->addDays($interval) : null;
                $rows[] = (object) ['item_id' => $item->item_id, 'sku' => $item->sku, 'name' => $item->name,
                    'warehouse_id' => $warehouse->id, 'warehouse' => $warehouse->name, 'abc_class' => $class,
                    'interval_days' => $interval, 'last_count_at' => $previous, 'next_due_at' => $next?->toIso8601String(),
                    'due' => $next === null || $next->lte($now)];
            }
        }

        return ['calculated_at' => $now->toIso8601String(), 'items' => $rows];
    }

    public function create(array $input, int $actor): object
    {
        $this->permit('create');
        $this->access->requireLocations($input['location_ids']);
        if ($input['mode'] === 'cycle' && empty($input['item_ids']) && empty($input['abc_class'])) {
            $this->fail('item_ids', 'Pilih barang atau kelas ABC untuk hitung siklus.');
        }
        if (! empty($input['item_ids']) && ! empty($input['abc_class'])) {
            $this->fail('abc_class', 'Pilih barang atau kelas ABC, bukan keduanya.');
        }
        sort($input['location_ids']);
        if (isset($input['item_ids'])) {
            sort($input['item_ids']);
        }
        $hash = $this->hash($input, $actor);
        $db = DB::connection('gudang');
        $id = $db->transaction(function () use ($db, $input, $actor, $hash) {
            $table = $db->table('gudang_counts');
            $inserted = $table->insertOrIgnore(['public_id' => (string) Str::uuid(), 'status' => 'counting', 'mode' => $input['mode'],
                'request_key' => $input['request_key'], 'payload_hash' => $hash, 'user_id' => $actor, 'cutoff_at' => now(), 'created_at' => now()]);
            $count = $table->where('request_key', $input['request_key'])->lockForUpdate()->firstOrFail();
            if (! hash_equals($count->payload_hash, $hash)) {
                $this->fail('request_key', 'Kunci sudah digunakan untuk permintaan lain.');
            }
            if (! $inserted) {
                return $count->id;
            }
            // Ledger locks the same location rows before every movement, fixing the snapshot boundary.
            $db->table('gudang_locations')->whereIn('id', $input['location_ids'])->orderBy('id')->lockForUpdate()->get();
            foreach ($input['location_ids'] as $location) {
                if (! $db->table('gudang_count_claims')->insertOrIgnore(['location_id' => $location, 'count_id' => $count->id])) {
                    $this->fail('location_ids', 'Lokasi sudah memiliki penghitungan aktif.');
                }
                $db->table('gudang_count_locations')->insert(['location_id' => $location, 'count_id' => $count->id]);
            }
            $itemIds = $input['item_ids'] ?? [];
            if ($input['mode'] === 'cycle' && ! empty($input['abc_class'])) {
                $warehouses = $db->table('gudang_locations')->whereIn('id', $input['location_ids'])->pluck('warehouse_id')->unique()->all();
                $itemIds = collect($this->due()['items'])->filter(fn ($row) => in_array($row->warehouse_id, $warehouses) && $row->abc_class === $input['abc_class'] && $row->due)->pluck('item_id')->unique()->all();
                if (! $itemIds) {
                    $this->fail('abc_class', 'Tidak ada barang kelas ini yang jatuh tempo pada gudang terpilih.');
                }
            }
            $items = $db->table('gudang_items')
                ->when($input['mode'] === 'cycle', fn ($q) => $q->whereIn('id', $itemIds))
                ->orderBy('id')->get(['id', 'batch_enabled']);
            $balances = $db->table('gudang_stock_balances')
                ->whereIn('location_id', $input['location_ids'])
                ->whereIn('item_id', $items->pluck('id'))
                ->get(['item_id', 'location_id', 'quantity_milli'])
                ->keyBy(fn ($row) => $row->item_id.':'.$row->location_id);
            $batches = $db->table('gudang_batches')
                ->whereIn('item_id', $items->where('batch_enabled', true)->pluck('id'))
                ->orderBy('id')->get(['id', 'item_id'])->groupBy('item_id');
            $batchBalances = $db->table('gudang_batch_balances')
                ->whereIn('location_id', $input['location_ids'])
                ->whereIn('batch_id', $batches->flatten()->pluck('id'))
                ->get(['batch_id', 'location_id', 'quantity_milli'])
                ->keyBy(fn ($row) => $row->batch_id.':'.$row->location_id);
            $rows = [];
            $append = function (array $row) use (&$rows, $db) {
                $rows[] = $row;
                if (count($rows) === 500) {
                    $db->table('gudang_count_lines')->insert($rows);
                    $rows = [];
                }
            };
            foreach ($input['location_ids'] as $location) {
                foreach ($items as $item) {
                    if ($item->batch_enabled) {
                        foreach ($batches->get($item->id, collect()) as $batch) {
                            $balance = $batchBalances->get($batch->id.':'.$location);
                            $append(['count_id' => $count->id, 'item_id' => $item->id,
                                'location_id' => $location, 'batch_id' => $batch->id,
                                'snapshot_milli' => $balance->quantity_milli ?? 0]);
                        }
                    } else {
                        $balance = $balances->get($item->id.':'.$location);
                        $append(['count_id' => $count->id, 'item_id' => $item->id,
                            'location_id' => $location, 'batch_id' => null,
                            'snapshot_milli' => $balance->quantity_milli ?? 0]);
                    }
                }
            }
            if ($rows) {
                $db->table('gudang_count_lines')->insert($rows);
            }
            if (! $db->table('gudang_count_lines')->where('count_id', $count->id)->exists()) {
                $this->fail('item_ids', 'Belum ada barang atau batch yang dapat dihitung pada pilihan ini.');
            }
            $db->table('gudang_counts')->where('id', $count->id)->update(['cutoff_at' => now()]);

            return $count->id;
        }, 3);

        return $this->show($id);
    }

    private function mutation(int $id, array $input, int $actor, string $action, callable $work): object
    {
        $db = DB::connection('gudang');
        $hash = $this->hash([$id, $action, $input], $actor);
        $db->transaction(function () use ($db, $id, $input, $actor, $action, $work, $hash) {
            $count = $this->scoped($id, true);
            $inserted = $db->table('gudang_count_actions')->insertOrIgnore(['count_id' => $id, 'user_id' => $actor, 'action' => $action,
                'request_key' => $input['request_key'], 'payload_hash' => $hash, 'note' => $input['note'] ?? null, 'created_at' => now()]);
            $previous = $db->table('gudang_count_actions')->where('request_key', $input['request_key'])->firstOrFail();
            if (! hash_equals($previous->payload_hash, $hash)) {
                $this->fail('request_key', 'Kunci sudah digunakan untuk permintaan lain.');
            }
            if ($inserted) {
                $work($count, $db);
            }
        }, 3);

        return $this->show($id);
    }

    public function record(int $id, array $input, int $actor): object
    {
        $this->permit('count');

        return $this->mutation($id, $input, $actor, 'count', function ($count, $db) use ($input, $actor) {
            if ($count->status !== 'counting') {
                $this->fail('status', 'Penghitungan tidak sedang berlangsung.');
            }
            foreach ($input['lines'] as $entry) {
                $line = $db->table('gudang_count_lines')->where('count_id', $count->id)->where('id', $entry['id'])->first();
                if (! $line) {
                    $this->fail('lines', 'Baris tidak ditemukan.');
                }
                $recount = $entry['recount'] ?? false;
                if ($recount && ($line->counted_milli === null || (int) $line->counter_id === $actor)) {
                    $this->fail('lines', 'Hitung ulang harus dilakukan petugas lain setelah hitungan pertama.');
                }
                if (! $recount && $line->counter_id !== null && (int) $line->counter_id !== $actor) {
                    $this->fail('lines', 'Baris sudah ditugaskan kepada petugas lain.');
                }
                $values = $recount ? ['recount_milli' => StockLedger::milli($entry['quantity']), 'recount_by' => $actor] : ['counted_milli' => StockLedger::milli($entry['quantity']), 'counter_id' => $actor, 'recount_milli' => null, 'recount_by' => null];
                $db->table('gudang_count_lines')->where('id', $line->id)->update($values);
            }
        });
    }

    public function act(int $id, array $input, int $actor): object
    {
        $action = $input['action'];
        $this->permit(in_array($action, ['approve', 'reject'], true) ? 'approve' : ($action === 'submit' ? 'count' : 'create'));

        return $this->mutation($id, $input, $actor, $action, function ($count, $db) use ($input, $actor, $action) {
            if (in_array($action, ['approve', 'reject'], true) && ! trim($input['note'] ?? '')) {
                $this->fail('note', 'Alasan wajib diisi.');
            }
            $lines = $db->table('gudang_count_lines')->where('count_id', $count->id)->orderBy('location_id')->orderBy('item_id')->orderBy('batch_id')->get();
            if ($action === 'submit') {
                if ($count->status !== 'counting') {
                    $this->fail('status', 'Penghitungan tidak sedang berlangsung.');
                }
                foreach ($lines as $line) {
                    if ($line->counted_milli === null) {
                        $this->fail('lines', 'Semua baris harus dihitung.');
                    }
                    if (abs($line->counted_milli - $line->snapshot_milli) > max(1000, abs($line->snapshot_milli) * 0.1) && $line->recount_milli === null) {
                        $this->fail('lines', 'Selisih besar memerlukan hitung ulang oleh petugas lain.');
                    }
                }
                $db->table('gudang_counts')->where('id', $count->id)->update(['status' => 'pending', 'submitted_at' => now()]);

                return;
            }
            if ($action === 'cancel') {
                if (! in_array($count->status, ['draft', 'counting'], true)) {
                    $this->fail('status', 'Penghitungan tidak dapat dibatalkan.');
                }
            } elseif ($count->status !== 'pending') {
                $this->fail('status', 'Penghitungan belum menunggu persetujuan.');
            }
            $documentId = null;
            if ($action === 'approve') {
                $publicId = (string) Str::uuid();
                $documentId = $db->table('gudang_stock_documents')->insertGetId(['public_id' => $publicId, 'type' => 'adjustment', 'status' => 'approved',
                    'request_key' => 'count:'.$publicId, 'payload_hash' => hash('sha256', $publicId), 'user_id' => $actor,
                    'source' => 'Stock count '.$count->public_id, 'document_date' => now('Asia/Jakarta')->toDateString(), 'posted_at' => now()]);
                $doc = (object) ['id' => $documentId, 'type' => 'adjustment'];
                StockLedger::assignNumber($doc);
                $db->table('gudang_actions')->insert(['document_id' => $documentId, 'user_id' => $actor, 'action' => 'approve', 'request_key' => 'count:'.$publicId, 'payload_hash' => hash('sha256', $publicId), 'note' => $input['note'], 'created_at' => now()]);
                foreach ($lines as $line) {
                    $this->ledger->move($documentId, $line->item_id, $line->location_id, ($line->recount_milli ?? $line->counted_milli) - $line->snapshot_milli, $line->batch_id);
                }
            }
            $db->table('gudang_counts')->where('id', $count->id)->update(['status' => match ($action) {
                'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled'
            }, 'completed_at' => now(), 'document_id' => $documentId]);
            $db->table('gudang_count_claims')->where('count_id', $count->id)->delete();
        });
    }
}
