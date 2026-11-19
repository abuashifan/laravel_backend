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
        $seq->insertOrIgnore(['type' => $doc->type]);
        $seq->where('type', $doc->type)->increment('last_number');
        $doc->serial = $seq->value('last_number');
        $db->table('gudang_stock_documents')->where('id', $doc->id)->update(['serial' => $doc->serial]);
    }

    /** Pemanggil harus memegang transaksi dokumen. Update bersyarat juga mengunci saldo di PostgreSQL. */
    public function move(int $document, int $item, int $location, int $amount, ?int $batchId = null, array $allocations = [], array $snapshot = []): array
    {
        if ($amount === 0) {
            return [];
        }
        $db = DB::connection('gudang');
        $goods = $db->table('gudang_items')->where('id', $item)->lockForUpdate()->firstOrFail();
        $db->table('gudang_locations')->where('id', $location)->lockForUpdate()->firstOrFail();
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
        $movement = $db->table('gudang_stock_movements')->insertGetId($key + [
            'quantity_milli' => $amount, 'document_id' => $document, 'occurred_at' => now(),
        ] + $snapshot);

        return app(BatchStock::class)->record($document, $movement, $goods, $location, $amount, $batchId, $allocations);
    }

    /** Satu penerimaan final, dokumen dan seluruh saldo ditulis dalam transaksi yang sama. */
    public function receive(array $input, int $actor): object
    {
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $input, $actor) {
            $signatureLines = [];
            foreach ($input['lines'] as $line) {
                $signatureLine = ['item_id' => (int) $line['item_id'], 'location_id' => (int) $line['location_id'],
                    'quantity_milli' => self::milli($line['quantity'])];
                foreach (['unit_id', 'batch_id'] as $field) {
                    if (isset($line[$field])) {
                        $signatureLine[$field] = (int) $line[$field];
                    }
                }
                $signatureLines[] = $signatureLine;
            }
            sort($signatureLines);
            $signature = ['receipt', $actor, $input['source'], $signatureLines];
            if (isset($input['document_date'])) {
                $signature[] = $input['document_date'];
            }
            if (isset($input['parent_id'])) {
                $signature[] = (int) $input['parent_id'];
            }
            $hash = hash('sha256', json_encode($signature, JSON_THROW_ON_ERROR));
            $documents = $db->table('gudang_stock_documents');
            $inserted = $documents->insertOrIgnore([
                'public_id' => (string) Str::uuid(), 'type' => 'receipt',
                'document_date' => $input['document_date'] ?? now('Asia/Jakarta')->toDateString(),
                'parent_id' => $input['parent_id'] ?? null,
                'location_id' => count(array_unique(array_column($signatureLines, 'location_id'))) === 1 ? $signatureLines[0]['location_id'] : null,
                'user_id' => $actor, 'source' => $input['source'], 'request_key' => $input['request_key'],
                'payload_hash' => $hash, 'posted_at' => now(),
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
                foreach ($signatureLines as $line) {
                    $db->table('gudang_items')->where('id', $line['item_id'])->lockForUpdate()->firstOrFail();
                    $converted = app(StockUnits::class)->convert($line['item_id'], self::decimal($line['quantity_milli']), $line['unit_id'] ?? null);
                    $this->move($document->id, $line['item_id'], $line['location_id'], $converted['quantity_milli'], $line['batch_id'] ?? null, [], $converted);
                }
            }

            return (object) ['id' => $document->id, 'public_id' => $document->public_id, 'number' => 'TRM-'.$document->serial];
        }, 3);
    }

    public static function decimal(int $quantity): string
    {
        return ($quantity < 0 ? '-' : '').intdiv(abs($quantity), 1000).'.'.str_pad((string) (abs($quantity) % 1000), 3, '0', STR_PAD_LEFT);
    }
}
