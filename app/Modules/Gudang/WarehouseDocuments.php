<?php

namespace App\Modules\Gudang;

use App\Shared\Permission\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseDocuments
{
    public const PREFIX = ['receipt' => 'TRM', 'order' => 'PO', 'issue' => 'SJ', 'transfer' => 'TF', 'putaway' => 'PT', 'adjustment' => 'ADJ', 'release' => 'PLS', 'reversal' => 'KOR', 'customer_return' => 'RPL', 'supplier_return' => 'RPM'];

    public const PERMISSION = [
        'receipt' => 'gudang.receipts.create', 'order' => 'gudang.receipts.create', 'issue' => 'gudang.issues.create',
        'transfer' => 'gudang.transfers.create', 'putaway' => 'gudang.transfers.create', 'adjustment' => 'gudang.stock.adjust',
        'release' => 'gudang.stock.approve', 'reversal' => 'gudang.stock.approve',
        'customer_return' => 'gudang.receipts.create', 'supplier_return' => 'gudang.issues.create',
    ];

    public function __construct(private StockLedger $ledger, private WarehouseAccess $access, private PermissionService $permissions) {}

    public function draft(array $input, int $actor): object
    {
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $input, $actor) {
            $type = $input['type'];
            $this->check($type !== 'reversal', 'Dokumen balik dibuat melalui dokumen asal.');
            abort_unless($this->permissions->can(self::PERMISSION[$type]), 403);
            $target = $input['target_id'] ?? null;
            $parent = $input['parent_id'] ?? null;
            $relocation = in_array($type, ['transfer', 'putaway', 'release'], true);
            $return = in_array($type, ['customer_return', 'supplier_return'], true);
            $this->check($relocation === ($target !== null), 'Lokasi tujuan wajib untuk perpindahan barang.');
            $this->check($return === ($parent !== null), 'Dokumen asal wajib untuk retur.');
            $this->access->requireLocations(array_filter([$input['location_id'], $target]));
            $this->requireStockCondition($type, (int) $input['location_id'], $target);
            if ($type === 'putaway') {
                $this->check($db->table('gudang_locations')->whereIn('id', [$input['location_id'], $target])->distinct()->count('warehouse_id') === 1, 'Putaway hanya di dalam satu gudang.');
            }
            $quantities = [];
            $dimensions = [];
            foreach ($input['lines'] as $line) {
                $item = (int) $line['item_id'];
                $quantities[$item] = StockLedger::milli($line['quantity']);
                foreach (['unit_id', 'batch_id'] as $field) {
                    if (isset($line[$field])) {
                        $dimensions[$item][$field] = (int) $line[$field];
                    }
                }
            }
            ksort($quantities);
            ksort($dimensions);
            $signature = [$type, $actor, $input['source'], (int) $input['location_id'], (int) $target, $input['document_date'], $quantities];
            if ($parent !== null) {
                $signature[] = (int) $parent;
            }
            if ($dimensions !== []) {
                $signature[] = $dimensions;
            }
            $hash = hash('sha256', json_encode($signature, JSON_THROW_ON_ERROR));
            $previous = $db->table('gudang_stock_documents')->where('request_key', $input['request_key'])->first();
            if ($previous) {
                $this->check(hash_equals($previous->payload_hash, $hash), 'Kunci simpan sudah digunakan.');

                return $previous;
            }
            $lines = [];
            foreach ($quantities as $item => $quantity) {
                $db->table('gudang_items')->where('id', $item)->lockForUpdate()->firstOrFail();
                $converted = app(StockUnits::class)->convert($item, StockLedger::decimal($quantity), $dimensions[$item]['unit_id'] ?? null);
                $amount = $converted['quantity_milli'];
                $this->check($amount !== 0 && ($amount > 0 || $type === 'adjustment'), 'Jumlah tidak valid.');
                $batch = $dimensions[$item]['batch_id'] ?? null;
                $this->check($batch === null || $db->table('gudang_batches')->where('id', $batch)->where('item_id', $item)->exists(), 'Batch tidak sesuai dengan barang.');
                $lines[$item] = $converted + ['batch_id' => $batch];
            }
            if ($return) {
                $this->validateReturn((int) $parent, $type, $lines);
            }
            $docs = $db->table('gudang_stock_documents');
            $inserted = $docs->insertOrIgnore([
                'public_id' => (string) Str::uuid(), 'request_key' => $input['request_key'], 'payload_hash' => $hash,
                'type' => $type, 'status' => 'draft', 'source' => $input['source'], 'user_id' => $actor,
                'location_id' => $input['location_id'], 'target_id' => $target, 'parent_id' => $parent,
                'document_date' => $input['document_date'], 'posted_at' => now(),
            ]);
            $doc = $docs->where('request_key', $input['request_key'])->firstOrFail();
            $this->check(hash_equals($doc->payload_hash, $hash), 'Kunci simpan sudah digunakan.');
            if ($inserted) {
                StockLedger::assignNumber($doc);
                foreach ($lines as $item => $line) {
                    $db->table('gudang_doc_lines')->insert(['document_id' => $doc->id, 'item_id' => $item] + $line);
                }
                $this->audit($doc, 'draft', $actor, 'draft:'.$input['request_key'], $hash, $type === 'release' || $return ? $input['source'] : null);
            }

            return $doc;
        }, 3);
    }

    private function validateReturn(int $parentId, string $type, array $lines, ?int $exclude = null): void
    {
        $db = DB::connection('gudang');
        $parent = $db->table('gudang_stock_documents')->where('id', $parentId)->lockForUpdate()->firstOrFail();
        $expected = $type === 'customer_return' ? 'issue' : 'receipt';
        $this->check($parent->type === $expected && $parent->status === 'posted', 'Dokumen asal retur harus sudah terposting dan sesuai jenis retur.');
        $locations = $db->table('gudang_stock_movements')->where('document_id', $parentId)->pluck('location_id')->all();
        $this->access->requireLocations($locations);
        $direction = $type === 'customer_return' ? -1 : 1;
        foreach ($lines as $item => $line) {
            $quantity = (int) $line['quantity_milli'];
            $original = (int) $db->table('gudang_stock_movements')->where('document_id', $parentId)->where('item_id', $item)->sum('quantity_milli') * $direction;
            $returned = $db->table('gudang_doc_lines as line')->join('gudang_stock_documents as doc', 'doc.id', '=', 'line.document_id')
                ->where('doc.parent_id', $parentId)->where('doc.type', $type)->where('doc.status', 'posted')
                ->when($exclude !== null, fn ($query) => $query->where('doc.id', '<>', $exclude))->where('line.item_id', $item);
            $this->check($quantity > 0 && $quantity <= $original - (int) (clone $returned)->sum('line.quantity_milli'), 'Jumlah retur melebihi sisa yang dapat diretur.');
            if ($db->table('gudang_items')->where('id', $item)->value('batch_enabled')) {
                $batchId = $line['batch_id'] ?? null;
                $this->check($batchId !== null, 'Retur barang batch memerlukan batch asal.');
                $batchQuantity = (int) $db->table('gudang_movement_batches as batch')->join('gudang_stock_movements as movement', 'movement.id', '=', 'batch.movement_id')
                    ->where('movement.document_id', $parentId)->where('movement.item_id', $item)->where('batch.batch_id', $batchId)->sum('batch.quantity_milli') * $direction;
                $this->check($quantity <= $batchQuantity - (int) (clone $returned)->where('line.batch_id', $batchId)->sum('line.quantity_milli'), 'Jumlah retur melebihi sisa batch pada dokumen asal.');
            }
        }
    }

    private function audit(object $doc, string $action, int $actor, string $key, string $hash, ?string $note): void
    {
        DB::connection('gudang')->table('gudang_actions')->insert([
            'document_id' => $doc->id, 'user_id' => $actor, 'action' => $action, 'request_key' => $key,
            'payload_hash' => $hash, 'note' => $note, 'created_at' => now(),
        ]);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['document' => $message]);
        }
    }

    private function requireStockCondition(string $type, int $origin, ?int $target): void
    {
        $locations = DB::connection('gudang')->table('gudang_locations')->whereIn('id', array_filter([$origin, $target]))->get()->keyBy('id');
        $restricted = in_array($locations[$origin]->kind, ['karantina', 'rusak'], true);
        if ($type === 'issue') {
            $this->check(! $restricted, 'Barang karantina atau rusak harus dilepas kondisinya sebelum pengeluaran normal.');
        }
        if ($type === 'release') {
            $this->check($restricted && $target !== null, 'Pelepasan kondisi harus berasal dari lokasi karantina atau rusak.');
            $this->check(in_array($locations[$target]->kind, ['simpan', 'terima'], true), 'Tujuan pelepasan harus lokasi simpan atau terima.');
            $this->check($locations[$origin]->warehouse_id === $locations[$target]->warehouse_id, 'Pelepasan kondisi harus di dalam satu gudang.');
        }
        if ($restricted && in_array($type, ['transfer', 'putaway'], true)) {
            $this->check($target !== null && in_array($locations[$target]->kind, ['karantina', 'rusak'], true), 'Gunakan dokumen pelepasan kondisi untuk memindahkan barang ke lokasi normal.');
        }
    }

    public function act(int $id, array $input, int $actor): object
    {
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $id, $input, $actor) {
            $docs = $db->table('gudang_stock_documents')->where('id', $id);
            $doc = (clone $docs)->lockForUpdate()->firstOrFail();
            $action = $input['action'];
            $permission = match ($action) {
                'approve','reject','resolve' => 'gudang.stock.approve',
                default => self::PERMISSION[$doc->type],
            };
            abort_unless($this->permissions->can($permission), 403);
            $locations = $doc->type === 'transfer' && $action === 'receive'
                ? [$doc->target_id] : [$doc->location_id];
            $this->access->requireLocations(array_filter($locations));
            if (in_array($action, ['post', 'ship'], true)) {
                $this->access->requireLocations(array_filter([$doc->location_id, $doc->target_id]));
                $this->requireStockCondition($doc->type, (int) $doc->location_id, $doc->target_id);
            }
            $key = 'act:'.$input['request_key'];
            $amounts = [];
            $receiptBatches = [];
            foreach ($input['lines'] ?? [] as $line) {
                $amounts[(int) $line['item_id']] = StockLedger::milli($line['quantity']);
                if (isset($line['batch_id'])) {
                    $receiptBatches[(int) $line['item_id']] = (int) $line['batch_id'];
                }
            }
            ksort($amounts);
            ksort($receiptBatches);
            $note = $input['note'] ?? ($doc->type === 'release' ? $doc->source : null);
            $resolution = $input['resolution'] ?? 'return';
            $acceptExtra = ($input['surplus'] ?? 'return') === 'accept';
            $signature = [$actor, $id, $action, $amounts, $note];
            if ($receiptBatches !== []) {
                $signature[] = $receiptBatches;
            }
            if ($action === 'resolve' && $acceptExtra) {
                $this->access->requireLocations([$doc->location_id, $doc->target_id]);
                $signature[] = 'accept_extra';
            }
            if ($action === 'resolve' && $resolution === 'loss') {
                $signature[] = 'loss';
            }
            $hash = hash('sha256', json_encode($signature, JSON_THROW_ON_ERROR));
            $previous = $db->table('gudang_actions')->where('request_key', $key)->first();
            if ($previous) {
                $this->check(hash_equals($previous->payload_hash, $hash), 'Kunci aksi sudah digunakan.');

                return $doc;
            }
            $allowed = match ($doc->status) {
                'draft' => $doc->type === 'receipt' ? ['cancel', 'post', 'receive'] : ['cancel', match ($doc->type) {
                    'transfer' => 'ship', 'adjustment' => 'submit', 'order' => 'order', default => 'post',
                }],
                'ordered','partial','transit' => ['receive'],
                'disputed' => ['resolve'],
                'pending' => ['approve', 'reject'],
                default => [],
            };
            $this->check(in_array($action, $allowed, true), 'Status dokumen sudah berubah. Muat ulang dokumen.');
            $lines = $db->table('gudang_doc_lines')->where('document_id', $id)->orderBy('item_id')->get();
            if ($action === 'post' && in_array($doc->type, ['customer_return', 'supplier_return'], true)) {
                $this->validateReturn((int) $doc->parent_id, $doc->type, $lines->keyBy('item_id')->map(fn ($line) => (array) $line)->all(), $id);
            }
            $status = match ($action) {
                'cancel' => 'cancelled', 'ship' => 'transit', 'submit' => 'pending', 'approve' => 'approved', 'reject' => 'rejected',
                'resolve' => 'resolved', 'receive' => 'received', 'order' => 'ordered', default => 'posted',
            };
            if ($action === 'receive') {
                $this->check(count($amounts) === $lines->count() && ! array_diff(array_keys($amounts), $lines->pluck('item_id')->all()), 'Isi jumlah aktual semua barang.');
                $this->check($doc->type === 'transfer' || array_sum($amounts) > 0, 'Isi jumlah penerimaan.');
            }
            if ($action === 'resolve') {
                $this->check(is_string($note) && trim($note) !== '', 'Alasan keputusan selisih wajib diisi.');
            }
            $delivery = [];
            foreach ($lines as $line) {
                $item = (int) $line->item_id;
                $qty = (int) $line->quantity_milli;
                $allocations = [];
                if ($action === 'ship' || ($action === 'post' && in_array($doc->type, ['issue', 'putaway', 'release', 'supplier_return'], true))) {
                    $allocations = $this->ledger->move($id, $item, $doc->location_id, -$qty, $line->batch_id);
                }
                if ($action === 'post' && in_array($doc->type, ['putaway', 'release'], true)) {
                    $this->ledger->move($id, $item, $doc->target_id, $qty, null, array_map(fn ($batch) => ['batch_id' => $batch['batch_id'], 'quantity_milli' => -$batch['quantity_milli']], $allocations));
                }
                if (($action === 'post' && in_array($doc->type, ['receipt', 'customer_return'], true)) || $action === 'approve') {
                    $this->ledger->move($id, $item, $doc->location_id, $qty, $line->batch_id);
                }
                if ($action === 'receive') {
                    $actual = $amounts[$item];
                    $remaining = $qty - $line->received_milli;
                    $this->check($actual >= 0 && ($doc->type === 'transfer' || $actual <= $remaining), 'Jumlah diterima melebihi sisa dokumen.');
                    if ($doc->type === 'transfer') {
                        $accepted = min($actual, $remaining);
                        $this->ledger->move($id, $item, $doc->target_id, $accepted, null, app(BatchStock::class)->transit($id, $item, $accepted));
                    } elseif ($actual > 0) {
                        $delivery[] = ['item_id' => $item, 'location_id' => $doc->location_id,
                            'quantity' => StockLedger::decimal($actual), 'batch_id' => $receiptBatches[$item] ?? $line->batch_id];
                    }
                    $db->table('gudang_doc_lines')->where('id', $line->id)->increment('received_milli', $actual);
                    if ($actual !== $remaining) {
                        $status = $doc->type === 'transfer' ? 'disputed' : 'partial';
                    }
                }
                if ($action === 'resolve') {
                    $missing = $qty - $line->received_milli;
                    if ($missing > 0 && $resolution === 'return') {
                        $this->ledger->move($id, $item, $doc->location_id, $missing, null, app(BatchStock::class)->transit($id, $item, $missing));
                    }
                    if ($missing < 0 && $acceptExtra) {
                        $extra = $this->ledger->move($id, $item, $doc->location_id, $missing, $line->batch_id);
                        $this->ledger->move($id, $item, $doc->target_id, -$missing, null, array_map(fn ($batch) => ['batch_id' => $batch['batch_id'], 'quantity_milli' => -$batch['quantity_milli']], $extra));
                    }
                }
            }
            if ($delivery !== []) {
                $this->ledger->receive([
                    'request_key' => 'delivery-'.hash('sha256', $key), 'source' => $doc->source,
                    'parent_id' => $id, 'document_date' => now('Asia/Jakarta')->toDateString(), 'lines' => $delivery,
                ], $actor);
            }
            $docs->update(['status' => $status, 'posted_at' => now()]);
            $auditAction = $action === 'resolve' ? 'resolve_'.$resolution.($acceptExtra ? '_extra' : '') : $action;
            $this->audit($doc, $auditAction, $actor, $key, $hash, $note);
            $doc->status = $status;

            return $doc;
        }, 3);
    }

    public function reverse(int $id, array $input, int $actor): object
    {
        abort_unless($this->permissions->can('gudang.stock.approve'), 403);
        $note = trim($input['note']);
        $this->check($note !== '', 'Alasan koreksi wajib diisi.');
        $key = 'reverse:'.$input['request_key'];
        $hash = hash('sha256', json_encode([$id, $actor, $note], JSON_THROW_ON_ERROR));
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $id, $actor, $note, $key, $hash) {
            $documents = $db->table('gudang_stock_documents');
            $reference = (clone $documents)->where('id', $id)->firstOrFail();
            // Penerimaan bertahap mengunci induk lebih dulu, sama dengan aksi receive.
            $parent = $reference->type === 'receipt' && $reference->parent_id !== null
                ? (clone $documents)->where('id', $reference->parent_id)->lockForUpdate()->firstOrFail()
                : null;
            $source = (clone $documents)->where('id', $id)->lockForUpdate()->firstOrFail();
            $movements = $db->table('gudang_stock_movements')->where('document_id', $id)
                ->selectRaw('item_id, location_id, sum(quantity_milli) as quantity_milli')
                ->groupBy('item_id', 'location_id')->orderBy('item_id')->orderBy('location_id')->get();
            $this->access->requireLocations($movements->pluck('location_id')->all());
            $previous = (clone $documents)->where('request_key', $key)->first();
            if ($previous) {
                $this->check(hash_equals($previous->payload_hash, $hash), 'Kunci koreksi sudah digunakan untuk permintaan lain.');

                return $previous;
            }
            $this->check($source->type !== 'reversal' && in_array($source->status, ['posted', 'approved', 'received', 'resolved'], true), 'Koreksi hanya untuk dokumen terposting yang sudah selesai.');
            $this->check($movements->isNotEmpty(), 'Koreksi penerimaan bertahap melalui bukti masing-masing kiriman.');
            $batchEffect = $db->table('gudang_movement_batches as batch')->join('gudang_stock_movements as movement', 'movement.id', '=', 'batch.movement_id')
                ->where('movement.document_id', $id)->groupBy('movement.item_id', 'movement.location_id', 'batch.batch_id')
                ->havingRaw('sum(batch.quantity_milli) <> 0')->exists();
            $this->check($batchEffect || $movements->contains(fn ($movement) => (int) $movement->quantity_milli !== 0), 'Saldo dokumen sudah kembali seluruhnya. Tidak ada mutasi bersih untuk dibalik.');
            $this->check(! $db->table('gudang_stock_documents')->where('parent_id', $id)->whereIn('type', ['customer_return', 'supplier_return'])->where('status', 'posted')->exists(), 'Koreksi dokumen retur terkait terlebih dahulu.');
            $this->check(! (clone $documents)->where('reverses_id', $id)->exists(), 'Dokumen ini sudah memiliki dokumen balik.');
            $number = self::PREFIX[$source->type].'-'.$source->serial;
            $reversal = (object) [
                'public_id' => (string) Str::uuid(), 'type' => 'reversal', 'status' => 'posted',
                'request_key' => $key, 'payload_hash' => $hash, 'user_id' => $actor,
                'source' => 'Koreksi '.$number, 'reverses_id' => $id,
                'document_date' => now('Asia/Jakarta')->toDateString(), 'posted_at' => now(),
                'location_id' => $source->location_id, 'target_id' => $source->target_id,
            ];
            $inserted = (clone $documents)->insertOrIgnore((array) $reversal);
            $reversal = (clone $documents)->where('request_key', $key)->first();
            $this->check($reversal !== null && hash_equals($reversal->payload_hash, $hash), 'Kunci koreksi sudah digunakan untuk permintaan lain.');
            if (! $inserted) {
                return $reversal;
            }
            StockLedger::assignNumber($reversal);
            foreach ($movements as $movement) {
                $batches = app(BatchStock::class)->inverse($id, (int) $movement->item_id, (int) $movement->location_id);
                if ($batches === []) {
                    $this->ledger->move($reversal->id, (int) $movement->item_id, (int) $movement->location_id, -(int) $movement->quantity_milli);
                } else {
                    foreach ($batches as $batch) {
                        $this->ledger->move($reversal->id, (int) $movement->item_id, (int) $movement->location_id, $batch['quantity_milli'], $batch['batch_id']);
                    }
                }
            }
            if ($parent) {
                $received = $movements->groupBy('item_id');
                foreach ($received as $item => $locations) {
                    $quantity = (int) $locations->sum('quantity_milli');
                    $updated = $db->table('gudang_doc_lines')->where('document_id', $parent->id)->where('item_id', $item)
                        ->where('received_milli', '>=', $quantity)->decrement('received_milli', $quantity);
                    $this->check($quantity > 0 && $updated === 1, 'Rincian penerimaan induk tidak sesuai dengan bukti kiriman.');
                }
                $remaining = $db->table('gudang_doc_lines')->where('document_id', $parent->id)->sum('received_milli');
                $parentStatus = (int) $remaining > 0 ? 'partial' : ($parent->type === 'order' ? 'ordered' : 'draft');
                (clone $documents)->where('id', $parent->id)->update(['status' => $parentStatus]);
                $this->audit($parent, 'reverse_delivery', $actor, 'parent:'.$key, $hash, $note);
            }
            (clone $documents)->where('id', $id)->update(['status' => 'reversed']);
            $count = $db->table('gudang_counts')->where('document_id', $id)->lockForUpdate()->first();
            if ($count) {
                $db->table('gudang_counts')->where('id', $count->id)->update(['status' => 'reversed']);
                $db->table('gudang_count_actions')->insert([
                    'count_id' => $count->id, 'user_id' => $actor, 'action' => 'reverse',
                    'request_key' => hash('sha256', 'count:'.$key), 'payload_hash' => $hash,
                    'note' => $note, 'created_at' => now(),
                ]);
            }
            $this->audit($source, 'reverse', $actor, 'source:'.$key, $hash, $note);
            $this->audit($reversal, 'reverse', $actor, $key, $hash, $note);

            return $reversal;
        }, 3);
    }
}
