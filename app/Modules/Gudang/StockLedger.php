<?php

namespace App\Modules\Gudang;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StockLedger
{
    public static function milli(int|float|string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', ltrim((string) $value, '-'), 2), 2, '');
        $absolute = (int) $whole * 1000 + (int) str_pad($fraction, 3, '0');
        return str_starts_with((string) $value, '-') ? -$absolute : $absolute;
    }

    public static function assignNumber(object $doc): void
    {
        $db = DB::connection('gudang');
        $seq = $db->table('gudang_number_sequences');
        $seq->insertOrIgnore(['type'=>$doc->type]);
        $seq->where('type',$doc->type)->increment('last_number');
        $doc->serial = $seq->value('last_number');
        $db->table('gudang_stock_documents')->where('id',$doc->id)->update(['serial'=>$doc->serial]);
    }

    /** Pemanggil harus memegang transaksi dokumen. Update bersyarat juga mengunci saldo di PostgreSQL. */
    public function move(int $document, int $item, int $location, int $amount): void
    {
        if ($amount === 0) {
            return;
        }
        $db = DB::connection('gudang');
        $key = ['item_id' => $item, 'location_id' => $location];
        $balances = $db->table('gudang_stock_balances');
        $balances->insertOrIgnore($key);
        $row = $balances->where($key);
        if ($amount < 0 && $db->table('gudang_settings')->where('key', 'allow_negative')->value('value') !== '1') {
            $row->where('quantity_milli', '>=', -$amount);
        }
        if (! $row->increment('quantity_milli', $amount)) {
            throw ValidationException::withMessages(['quantity' => 'Stok tersedia tidak mencukupi.']);
        }
        $db->table('gudang_stock_movements')->insert($key + [
            'quantity_milli' => $amount, 'document_id' => $document, 'occurred_at' => now(),
        ]);
    }

    /** Satu penerimaan final, dokumen dan seluruh saldo ditulis dalam transaksi yang sama. */
    public function receive(array $input, int $actor): object
    {
        $lines = [];
        foreach ($input['lines'] as $line) {
            $lines[] = [
                'item_id' => (int) $line['item_id'],
                'location_id' => (int) $line['location_id'],
                'quantity_milli' => self::milli($line['quantity']),
            ];
        }
        // Urutan kunci yang sama di semua transaksi juga mencegah deadlock saldo.
        sort($lines);
        $signature = ['receipt', $actor, $input['source'], $lines];
        $date = $input['document_date'] ?? null;
        if ($date !== null) {
            $signature[] = $date;
        }
        $hash = hash('sha256', json_encode($signature, JSON_THROW_ON_ERROR));
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $input, $actor, $lines, $hash) {
            $documents = $db->table('gudang_stock_documents');
            $inserted = $documents->insertOrIgnore([
                'public_id' => (string) Str::uuid(),
                'type' => 'receipt',
                'document_date' => $input['document_date'] ?? now()->toDateString(),
                'location_id' => count(array_unique(array_column($lines, 'location_id'))) === 1 ? $lines[0]['location_id'] : null,
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
                self::assignNumber($document);
                $db->table('gudang_actions')->insert([
                    'document_id' => $document->id, 'user_id' => $actor, 'action' => 'receive',
                    'request_key' => 'receipt:'.$input['request_key'], 'payload_hash' => $hash, 'created_at' => now(),
                ]);
                foreach ($lines as $line) {
                    $this->move($document->id, $line['item_id'], $line['location_id'], $line['quantity_milli']);
                }
            }

            return (object) [
                'id' => $document->id,
                'public_id' => $document->public_id,
                'number' => 'TRM-'.$document->serial,
            ];
        }, 3);
    }
}
