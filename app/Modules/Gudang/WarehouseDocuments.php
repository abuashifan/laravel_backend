<?php

namespace App\Modules\Gudang;

use App\Shared\Permission\PermissionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WarehouseDocuments
{
    public const PREFIX = ['receipt'=>'TRM', 'order'=>'PO', 'issue'=>'SJ', 'transfer'=>'TF', 'putaway'=>'PT', 'adjustment'=>'ADJ'];

    public const PERMISSION = [
        'receipt'=>'gudang.receipts.create', 'order'=>'gudang.receipts.create', 'issue'=>'gudang.issues.create',
        'transfer'=>'gudang.transfers.create', 'putaway'=>'gudang.transfers.create', 'adjustment'=>'gudang.stock.adjust',
    ];

    public function __construct(private StockLedger $ledger, private WarehouseAccess $access, private PermissionService $permissions) {}

    public function draft(array $input, int $actor): object
    {
        $type = $input['type'];
        abort_unless($this->permissions->can(self::PERMISSION[$type]), 403);
        $target = $input['target_id'] ?? null;
        $relocation = in_array($type, ['transfer', 'putaway'], true);
        $this->check($relocation === ($target !== null), 'Lokasi tujuan wajib untuk perpindahan barang.');
        $this->access->requireLocations(array_filter([$input['location_id'], $target]));
        $db = DB::connection('gudang');
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
                'public_id'=>(string) Str::uuid(), 'request_key'=>$input['request_key'], 'payload_hash'=>$hash,
                'type'=>$type, 'status'=>'draft', 'source'=>$input['source'], 'user_id'=>$actor,
                'location_id'=>$input['location_id'], 'target_id'=>$target, 'document_date'=>$input['document_date'], 'posted_at'=>now(),
            ]);
            $doc = $docs->where('request_key', $input['request_key'])->firstOrFail();
            $this->check(hash_equals($doc->payload_hash, $hash), 'Kunci simpan sudah digunakan.');
            if ($inserted) {
                foreach ($lines as $item => $quantity) {
                    $db->table('gudang_doc_lines')->insert(['document_id'=>$doc->id, 'item_id'=>$item, 'quantity_milli'=>$quantity]);
                }
                $this->audit($doc, 'draft', $actor, 'draft:'.$input['request_key'], $hash, null);
            }
            return $doc;
        }, 3);
    }

    private function audit(object $doc, string $action, int $actor, string $key, string $hash, ?string $note): void
    {
        DB::connection('gudang')->table('gudang_actions')->insert([
            'document_id'=>$doc->id, 'user_id'=>$actor, 'action'=>$action, 'request_key'=>$key,
            'payload_hash'=>$hash, 'note'=>$note, 'created_at'=>now(),
        ]);
    }

    private function check(bool $ok, string $message): void
    {
        if (! $ok) {
            throw ValidationException::withMessages(['document'=>$message]);
        }
    }

    public function act(int $id, array $input, int $actor): object
    {
        $db = DB::connection('gudang');
        return $db->transaction(function () use ($db, $id, $input, $actor) {
            $docs = $db->table('gudang_stock_documents')->where('id', $id);
            $doc = $docs->lockForUpdate()->firstOrFail();
            $action = $input['action'];
            $permission = match ($action) {
                'approve','reject','resolve' => 'gudang.stock.approve',
                default => self::PERMISSION[$doc->type],
            };
            abort_unless($this->permissions->can($permission), 403);
            $locations = $doc->type === 'transfer' && $action === 'receive'
                ? [$doc->target_id] : [$doc->location_id];
            $this->access->requireLocations(array_filter($locations));
            $key = 'act:'.$input['request_key'];
            $amounts = [];
            foreach ($input['lines'] ?? [] as $line) {
                $amounts[(int) $line['item_id']] = StockLedger::milli($line['quantity']);
            }
            ksort($amounts);
            $note = $input['note'] ?? null;
            $hash = hash('sha256', json_encode([$actor,$id,$action,$amounts,$note], JSON_THROW_ON_ERROR));
            $previous = $db->table('gudang_actions')->where('request_key', $key)->first();
            if ($previous) {
                $this->check(hash_equals($previous->payload_hash, $hash), 'Kunci aksi sudah digunakan.');
                return $doc;
            }
            $allowed = match ($doc->status) {
                'draft' => ['cancel', match ($doc->type) {
                    'transfer'=>'ship', 'adjustment'=>'submit', 'order'=>'order', default=>'post',
                }],
                'ordered','partial','transit' => ['receive'],
                'disputed' => ['resolve'],
                'pending' => ['approve','reject'],
                default => [],
            };
            $this->check(in_array($action, $allowed, true), 'Status dokumen sudah berubah. Muat ulang dokumen.');
            $lines = $db->table('gudang_doc_lines')->where('document_id', $id)->orderBy('item_id')->get();
            $status = match ($action) {
                'cancel'=>'cancelled', 'ship'=>'transit', 'submit'=>'pending', 'approve'=>'approved', 'reject'=>'rejected',
                'resolve'=>'resolved', 'receive'=>'received', 'order'=>'ordered', default=>'posted',
            };
            if ($action === 'receive') {
                $this->check(count($amounts) === $lines->count() && !array_diff(array_keys($amounts), $lines->pluck('item_id')->all()), 'Isi jumlah aktual semua barang.');
                $this->check($doc->type === 'transfer' || array_sum($amounts) > 0, 'Isi jumlah penerimaan.');
            }
            if ($action === 'resolve') {
                $this->check(is_string($note) && trim($note) !== '', 'Alasan pengembalian selisih ke asal wajib diisi.');
            }
            foreach ($lines as $line) {
                $item = (int) $line->item_id;
                $qty = (int) $line->quantity_milli;
                if ($action === 'ship' || ($action === 'post' && in_array($doc->type, ['issue','putaway'], true))) {
                    $this->ledger->move($id, $item, $doc->location_id, -$qty);
                }
                if ($action === 'post' && $doc->type === 'putaway') {
                    $this->ledger->move($id, $item, $doc->target_id, $qty);
                }
                if (($action === 'post' && $doc->type === 'receipt') || $action === 'approve') {
                    $this->ledger->move($id, $item, $doc->location_id, $qty);
                }
                if ($action === 'receive') {
                    $actual = $amounts[$item];
                    $remaining = $qty - $line->received_milli;
                    $this->check($actual >= 0 && $actual <= $remaining, 'Jumlah diterima melebihi sisa dokumen.');
                    $this->ledger->move($id, $item, $doc->target_id ?? $doc->location_id, $actual);
                    $db->table('gudang_doc_lines')->where('id', $line->id)->increment('received_milli', $actual);
                    if ($actual < $remaining) {
                        $status = $doc->type === 'transfer' ? 'disputed' : 'partial';
                    }
                }
                if ($action === 'resolve') {
                    $this->ledger->move($id, $item, $doc->location_id, $qty - $line->received_milli);
                }
            }
            $docs->update(['status'=>$status, 'posted_at'=>now()]);
            $this->audit($doc, $action, $actor, $key, $hash, $note);
            return $docs->firstOrFail();
        }, 3);
    }
}
