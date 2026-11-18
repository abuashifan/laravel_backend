<?php

namespace App\Modules\Gudang;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StockLedger
{
    /** Satu penerimaan final, dokumen dan seluruh saldo ditulis dalam transaksi yang sama. */
    public function receive(array $input, int $actor): object
    {
        $lines = [];
        foreach ($input['lines'] as $line) {
            [$whole, $fraction] = array_pad(explode('.', (string) $line['quantity'], 2), 2, '');
            $lines[] = [
                'item_id' => (int) $line['item_id'],
                'location_id' => (int) $line['location_id'],
                'quantity_milli' => (int) $whole * 1000 + (int) str_pad($fraction, 3, '0'),
            ];
        }
        // Urutan kunci yang sama di semua transaksi juga mencegah deadlock saldo.
        sort($lines);
        $hash = hash('sha256', json_encode(['receipt', $actor, $input['source'], $lines], JSON_THROW_ON_ERROR));
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $input, $actor, $lines, $hash) {
            $documents = $db->table('gudang_stock_documents');
            $inserted = $documents->insertOrIgnore([
                'public_id' => (string) Str::uuid(),
                'type' => 'receipt',
                'user_id' => $actor,
                'source' => $input['source'],
                'request_key' => $input['request_key'],
                'payload_hash' => $hash,
                'posted_at' => now(),
            ]);
            $document = $documents->where('request_key', $input['request_key'])->firstOrFail();
            if (! hash_equals($document->payload_hash, $hash)) {
                throw ValidationException::withMessages(['request_key' => 'Kunci simpan sudah dipakai untuk dokumen lain.']);
            }
            if ($inserted) {
                foreach ($lines as $line) {
                    $db->table('gudang_stock_movements')->insert(['document_id' => $document->id] + $line);
                    $key = ['item_id' => $line['item_id'], 'location_id' => $line['location_id']];
                    $db->table('gudang_stock_balances')->insertOrIgnore($key);
                    $db->table('gudang_stock_balances')->where($key)->increment('quantity_milli', $line['quantity_milli']);
                }
            }

            return (object) [
                'id' => $document->id,
                'public_id' => $document->public_id,
                'number' => 'TRM-'.$document->id,
            ];
        }, 3);
    }
}
