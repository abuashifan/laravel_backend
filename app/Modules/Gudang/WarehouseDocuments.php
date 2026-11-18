<?php

namespace App\Modules\Gudang;

use App\Shared\Permission\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseDocuments
{
    public const PREFIX = ['receipt' => 'TRM', 'order' => 'PO', 'issue' => 'SJ', 'transfer' => 'TF', 'putaway' => 'PT', 'adjustment' => 'ADJ', 'release' => 'PLS', 'reversal' => 'KOR'];

    public const PERMISSION = [
        'receipt' => 'gudang.receipts.create', 'order' => 'gudang.receipts.create', 'issue' => 'gudang.issues.create',
        'transfer' => 'gudang.transfers.create', 'putaway' => 'gudang.transfers.create', 'adjustment' => 'gudang.stock.adjust',
        'release' => 'gudang.stock.approve', 'reversal' => 'gudang.stock.approve',
    ];

    public function __construct(private StockLedger $ledger, private WarehouseAccess $access, private PermissionService $permissions) {}

    public function draft(array $input, int $actor): object
    {
        $type = $input['type'];
        $this->check($type !== 'reversal', 'Dokumen balik dibuat melalui dokumen asal.');
        abort_unless($this->permissions->can(self::PERMISSION[$type]), 403);
        $target = $input['target_id'] ?? null;
        $relocation = in_array($type, ['transfer', 'putaway', 'release'], true);
        $this->check($relocation === ($target !== null), 'Lokasi tujuan wajib untuk perpindahan barang.');
        $this->access->requireLocations(array_filter([$input['location_id'], $target]));
        $db = DB::connection('gudang');
        $this->requireStockCondition($type, (int) $input['location_id'], $target);
        if ($type === 'putaway') {
            $this->check($db->table('gudang_locations')->whereIn('id', [$input['location_id'], $target])->distinct()->count('warehouse_id') === 1, 'Putaway hanya di dalam satu gudang.');
        }
        $lines = [];
        foreach ($input['lines'] as $line) {
            $amount = StockLedger::milli($line['quantity']);
            $this->check($amount !== 0 && ($amount > 0 || $type === 'adjustment'), 'Jumlah tidak valid.');
            $lines[(int) $line['item_id']] = $amount;
        }
        ksort($lines);
        $hash = hash('sha256', json_encode([$type, $actor, $input['source'], (int) $input['location_id'], (int) $target, $input['document_date'], $lines], JSON_THROW_ON_ERROR));

        return $db->transaction(function () use ($db, $input, $actor, $type, $target, $lines, $hash) {
            $docs = $db->table('gudang_stock_documents');
            $inserted = $docs->insertOrIgnore([
                'public_id' => (string) Str::uuid(), 'request_key' => $input['request_key'], 'payload_hash' => $hash,
                'type' => $type, 'status' => 'draft', 'source' => $input['source'], 'user_id' => $actor,
                'location_id' => $input['location_id'], 'target_id' => $target, 'document_date' => $input['document_date'], 'posted_at' => now(),
            ]);
            $doc = $docs->where('request_key', $input['request_key'])->firstOrFail();
            $this->check(hash_equals($doc->payload_hash, $hash), 'Kunci simpan sudah digunakan.');
            if ($inserted) {
                StockLedger::assignNumber($doc);
                foreach ($lines as $item => $quantity) {
                    $db->table('gudang_doc_lines')->insert(['document_id' => $doc->id, 'item_id' => $item, 'quantity_milli' => $quantity]);
                }
                $this->audit($doc, 'draft', $actor, 'draft:'.$input['request_key'], $hash, $type === 'release' ? $input['source'] : null);
            }

            return $doc;
        }, 3);
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
            foreach ($input['lines'] ?? [] as $line) {
                $amounts[(int) $line['item_id']] = StockLedger::milli($line['quantity']);
            }
            ksort($amounts);
            $note = $input['note'] ?? ($doc->type === 'release' ? $doc->source : null);
            $resolution = $input['resolution'] ?? 'return';
            $acceptExtra = ($input['surplus'] ?? 'return') === 'accept';
            $signature = [$actor, $id, $action, $amounts, $note];
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
                if ($action === 'ship' || ($action === 'post' && in_array($doc->type, ['issue', 'putaway', 'release'], true))) {
                    $this->ledger->move($id, $item, $doc->location_id, -$qty);
                }
                if ($action === 'post' && in_array($doc->type, ['putaway', 'release'], true)) {
                    $this->ledger->move($id, $item, $doc->target_id, $qty);
                }
                if (($action === 'post' && $doc->type === 'receipt') || $action === 'approve') {
                    $this->ledger->move($id, $item, $doc->location_id, $qty);
                }
                if ($action === 'receive') {
                    $actual = $amounts[$item];
                    $remaining = $qty - $line->received_milli;
                    $this->check($actual >= 0 && ($doc->type === 'transfer' || $actual <= $remaining), 'Jumlah diterima melebihi sisa dokumen.');
                    if ($doc->type === 'transfer') {
                        $this->ledger->move($id, $item, $doc->target_id, min($actual, $remaining));
                    } elseif ($actual > 0) {
                        $delivery[] = ['item_id' => $item, 'location_id' => $doc->location_id,
                            'quantity' => sprintf('%d.%03d', intdiv($actual, 1000), $actual % 1000)];
                    }
                    $db->table('gudang_doc_lines')->where('id', $line->id)->increment('received_milli', $actual);
                    if ($actual !== $remaining) {
                        $status = $doc->type === 'transfer' ? 'disputed' : 'partial';
                    }
                }
                if ($action === 'resolve') {
                    $missing = $qty - $line->received_milli;
                    if ($missing > 0 && $resolution === 'return') {
                        $this->ledger->move($id, $item, $doc->location_id, $missing);
                    }
                    if ($missing < 0 && $acceptExtra) {
                        $this->ledger->move($id, $item, $doc->location_id, $missing);
                        $this->ledger->move($id, $item, $doc->target_id, -$missing);
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
            $this->check($movements->contains(fn ($movement) => (int) $movement->quantity_milli !== 0), 'Saldo dokumen sudah kembali seluruhnya. Tidak ada mutasi bersih untuk dibalik.');
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
                $this->ledger->move($reversal->id, (int) $movement->item_id, (int) $movement->location_id, -(int) $movement->quantity_milli);
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
            $this->audit($source, 'reverse', $actor, 'source:'.$key, $hash, $note);
            $this->audit($reversal, 'reverse', $actor, $key, $hash, $note);

            return $reversal;
        }, 3);
    }
}
