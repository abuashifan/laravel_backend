<?php

namespace App\Modules\Gudang;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class OutboundOrders
{
    public function __construct(private WarehouseAccess $access, private WarehouseDocuments $documents) {}

    public function reserve(array $input, int $actor): object
    {
        $db = DB::connection('gudang');
        $location = (int) $input['location_id'];
        $this->access->requireLocations([$location]);
        $amounts = $this->amounts($input['lines']);
        $hash = $this->hash([$actor, $location, $input['destination'], $amounts]);

        return $db->transaction(function () use ($db, $input, $actor, $location, $amounts, $hash) {
            $orders = $db->table('gudang_outbound_orders');
            $created = $orders->insertOrIgnore([
                'location_id' => $location,
                'destination' => $input['destination'],
                'request_key' => $input['request_key'],
                'payload_hash' => $hash,
                'user_id' => $actor,
                'created_at' => now(),
            ]);
            $order = $orders->where('request_key', $input['request_key'])->firstOrFail();
            $this->check(hash_equals($order->payload_hash, $hash), 'Kunci simpan sudah digunakan untuk pesanan lain.');
            if (! $created) {
                return $order;
            }

            $kind = $db->table('gudang_locations')->where('id', $location)->value('kind');
            $this->check(in_array($kind, ['simpan', 'terima'], true), 'Pilih lokasi simpan atau terima.');
            foreach ($amounts as $id => $amount) {
                $item = $db->table('gudang_items')->where('id', $id)->lockForUpdate()->firstOrFail();
                $this->check(! $item->batch_enabled, "Reservasi per batch belum tersedia untuk {$item->sku}.");
                $held = $this->balance($db, $id, $location)
                    ->whereRaw('quantity_milli - reserved_milli >= ?', [$amount])
                    ->increment('reserved_milli', $amount);
                $this->check($held === 1, "Stok tersedia {$item->sku} tidak mencukupi di lokasi ini.");
                $db->table('gudang_outbound_lines')->insert([
                    'order_id' => $order->id, 'item_id' => $id, 'unit' => $item->unit, 'quantity_milli' => $amount,
                ]);
            }

            return $order;
        }, 3);
    }

    public function act(int $id, array $input, int $actor): object
    {
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $id, $input, $actor) {
            $orders = $db->table('gudang_outbound_orders')->where('id', $id)->whereIn('location_id', $this->access->locations());
            $order = (clone $orders)->lockForUpdate()->firstOrFail();
            $actions = $db->table('gudang_outbound_actions');
            $amounts = $this->amounts($input['lines'] ?? []);
            $note = $input['note'] ?? null;
            $hash = $this->hash([$actor, $input['action'], $amounts, $note]);
            $previous = (clone $actions)->where('order_id', $id)->where('request_key', $input['request_key'])->first();
            if ($previous) {
                $this->check(hash_equals($previous->payload_hash, $hash), 'Kunci aksi sudah digunakan untuk tindakan lain.');

                return $order;
            }

            $this->check(in_array($order->status, ['reserved', 'partial'], true), 'Pesanan sudah ditutup.');
            $lines = $db->table('gudang_outbound_lines')->where('order_id', $id)->orderBy('item_id')->get();
            $outstanding = [];
            foreach ($lines as $line) {
                if ($line->shipped_milli < $line->quantity_milli) {
                    $outstanding[$line->item_id] = (int) $line->quantity_milli - (int) $line->shipped_milli;
                }
            }
            $cancel = $input['action'] === 'cancel';
            $release = $cancel ? $outstanding : $amounts;
            $this->check($release !== [], 'Isi jumlah barang yang dikirim.');
            foreach ($release as $item => $amount) {
                $this->check($amount <= ($outstanding[$item] ?? 0), 'Jumlah kirim melebihi sisa pesanan.');
                // Gunakan urutan dan kunci barang yang sama dengan buku stok.
                $db->table('gudang_items')->where('id', $item)->lockForUpdate()->firstOrFail();
                $updated = $this->balance($db, $item, $order->location_id)
                    ->where('reserved_milli', '>=', $amount)
                    ->decrement('reserved_milli', $amount);
                $this->check($updated === 1, 'Saldo reservasi berubah. Muat ulang pesanan.');
            }

            $documentId = null;
            $status = 'cancelled';
            if (! $cancel) {
                $documentId = $this->ship($order, $amounts, $input['request_key'], $actor);
                foreach ($amounts as $item => $amount) {
                    $db->table('gudang_outbound_lines')->where(['order_id' => $id, 'item_id' => $item])
                        ->increment('shipped_milli', $amount);
                }
                $status = $outstanding === $amounts ? 'shipped' : 'partial';
            }
            $order->status = $status;
            $orders->update(['status' => $status]);
            $actions->insert([
                'order_id' => $id, 'document_id' => $documentId, 'action' => $input['action'],
                'request_key' => $input['request_key'], 'payload_hash' => $hash, 'note' => $note,
                'user_id' => $actor, 'created_at' => now(),
            ]);

            return $order;
        }, 3);
    }

    private function ship(object $order, array $amounts, string $requestKey, int $actor): int
    {
        $lines = [];
        foreach ($amounts as $item => $amount) {
            $lines[] = ['item_id' => $item, 'quantity' => StockLedger::decimal($amount)];
        }
        $key = hash('sha256', "outbound:{$order->id}:{$requestKey}");
        $doc = $this->documents->draft([
            'type' => 'issue',
            'request_key' => 'outbound-draft-'.$key,
            'source' => $order->destination,
            'location_id' => $order->location_id,
            'document_date' => now('Asia/Jakarta')->toDateString(),
            'lines' => $lines,
        ], $actor);
        $this->documents->act($doc->id, ['action' => 'post', 'request_key' => 'outbound-ship-'.$key], $actor);

        return $doc->id;
    }

    private function balance(Connection $db, int $item, int $location): Builder
    {
        return $db->table('gudang_stock_balances')->where(['item_id' => $item, 'location_id' => $location]);
    }

    private function amounts(array $lines): array
    {
        $amounts = [];
        foreach ($lines as $line) {
            $amounts[(int) $line['item_id']] = StockLedger::milli($line['quantity']);
        }
        ksort($amounts);

        return $amounts;
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
