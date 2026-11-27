<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosGudangReceipt;
use App\Modules\Pos\Models\PosGudangReceiptLine;
use App\Modules\Pos\Models\PosProduct;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kotak masuk kiriman Finlite Gudang (Fase 4a Gudang).
 *
 * Kiriman datang dari Gudang lewat gateway dan menunggu. Stok toko HANYA
 * bertambah saat petugas mengonfirmasi jumlah yang benar-benar diterima, dan
 * selisih tidak mengubah stok Gudang: Gudang melihatnya sebagai pengecualian.
 */
class PosGudangReceiptService
{
    public function __construct(
        private readonly PosStockService $stock,
        private readonly PosAuditService $audit,
    ) {}

    /**
     * Simpan kiriman baru. Idempoten menurut `key`: kiriman yang sudah ada tidak diubah.
     *
     * @param  array{key:string, number:string, from:string, shipped_at:string, lines:list<array{product_id:int, sku:string, name:string, unit:string, quantity:string}>}  $shipment
     */
    public function deliver(array $shipment): PosGudangReceipt
    {
        return DB::connection('pos')->transaction(function () use ($shipment): PosGudangReceipt {
            $existing = PosGudangReceipt::query()->where('receipt_key', $shipment['key'])->first();
            if ($existing) {
                return $existing;
            }
            $receipt = PosGudangReceipt::query()->create([
                'receipt_key' => $shipment['key'],
                'number' => $shipment['number'],
                'source_name' => $shipment['from'],
                'shipped_at' => $shipment['shipped_at'],
                'status' => 'pending',
            ]);
            foreach ($shipment['lines'] as $line) {
                $receipt->lines()->create([
                    'product_id' => $line['product_id'],
                    'sku' => $line['sku'],
                    'name' => mb_substr($line['name'], 0, 160),
                    'unit' => $line['unit'],
                    'expected_quantity' => $line['quantity'],
                ]);
            }

            return $receipt;
        });
    }

    /**
     * Konfirmasi penerimaan. Setiap baris wajib diisi (0 bila tidak ada yang datang).
     * Selisih butuh catatan. Konfirmasi ulang untuk kiriman yang sudah dikonfirmasi
     * mengembalikan hasil yang tersimpan tanpa mengubah stok lagi.
     *
     * @param  list<array{id:int, actual_quantity:numeric-string|float|int}>  $lines
     */
    public function receive(User $user, int $id, array $lines, ?string $note): PosGudangReceipt
    {
        return DB::connection('pos')->transaction(function () use ($user, $id, $lines, $note): PosGudangReceipt {
            /** @var PosGudangReceipt $receipt */
            $receipt = PosGudangReceipt::query()->lockForUpdate()->findOrFail($id);
            if ($receipt->status !== 'pending') {
                return $receipt->load('lines');
            }

            $actual = [];
            foreach ($lines as $line) {
                $actual[(int) $line['id']] = round((float) $line['actual_quantity'], 3);
            }
            $receipt->load('lines');
            $errors = [];
            foreach ($receipt->lines as $index => $line) {
                if (! array_key_exists($line->id, $actual)) {
                    $errors["lines.{$index}"] = ["Isi jumlah diterima untuk {$line->name}."];
                } elseif ($line->product_id === null && $actual[$line->id] > 0) {
                    $errors["lines.{$index}"] = ["{$line->name} belum ada di daftar produk Kasir, jadi belum bisa diterima. Isi 0 atau tambahkan produknya dulu."];
                }
            }
            if (count($actual) !== $receipt->lines->count()) {
                $errors['lines'] = ['Jumlah baris tidak sesuai dengan kiriman.'];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $different = $receipt->lines->contains(fn (PosGudangReceiptLine $line) => abs($actual[$line->id] - (float) $line->expected_quantity) > 0.0004);
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            if ($different && $note === null) {
                throw ValidationException::withMessages(['note' => ['Jumlah diterima berbeda dengan yang dikirim. Tulis alasan selisihnya.']]);
            }

            foreach ($receipt->lines as $line) {
                $line->forceFill(['actual_quantity' => $actual[$line->id]])->save();
                if ($line->product_id !== null && $actual[$line->id] > 0) {
                    $product = PosProduct::query()->find($line->product_id);
                    if ($product) {
                        $this->stock->move($product, $actual[$line->id], 'restock', 'pos_gudang_receipt', $receipt->id, $receipt->number, $user->id);
                    }
                }
            }
            $receipt->forceFill([
                'status' => $different ? 'discrepancy' : 'received',
                'note' => $note,
                'received_by' => $user->id,
                'received_by_name' => $user->name,
                'received_at' => now(),
            ])->save();

            $this->audit->record(
                'stock.gudang',
                sprintf('Kiriman Gudang %s diterima: %d jenis barang%s.', $receipt->number, $receipt->lines->count(), $different ? ', ada selisih' : ''),
                $user,
                ['type' => 'gudang_receipt', 'id' => $receipt->id, 'label' => $receipt->number],
                [],
                $note,
            );

            return $receipt;
        });
    }
}
