<?php

namespace App\Modules\Gudang;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class StockUnits
{
    public function save(int $itemId, string $name, string $factor, ?int $id = null): object
    {
        Validator::make(['factor' => $factor], ['factor' => ['required', 'numeric', 'gt:0', 'regex:/^\d{1,8}(\.\d{1,3})?$/D']])->validate();
        $db = DB::connection('gudang');

        return $db->transaction(function () use ($db, $itemId, $name, $factor, $id) {
            $item = $db->table('gudang_items')->where('id', $itemId)->lockForUpdate()->firstOrFail();
            $name = trim($name);
            $duplicate = $name === '' || mb_strlen($name) > 24 || mb_strtolower($name) === mb_strtolower($item->unit);
            $names = $db->table('gudang_item_units')->where('item_id', $itemId)->when($id, fn ($query) => $query->where('id', '<>', $id))->pluck('name');
            if ($duplicate || $names->contains(fn ($other) => mb_strtolower($other) === mb_strtolower($name))) {
                throw ValidationException::withMessages(['name' => 'Nama satuan kosong, terlalu panjang atau sudah digunakan.']);
            }
            $values = ['name' => $name, 'factor_milli' => StockLedger::milli($factor)];
            if ($id === null) {
                $id = $db->table('gudang_item_units')->insertGetId(['item_id' => $itemId] + $values);
            } else {
                $db->table('gudang_item_units')->where('id', $id)->where('item_id', $itemId)->update($values);
            }

            return $db->table('gudang_item_units')->where('id', $id)->where('item_id', $itemId)->firstOrFail();
        }, 3);
    }

    public function convert(int $itemId, string|int|float $quantity, ?int $unitId = null): array
    {
        $db = DB::connection('gudang');
        $item = $db->table('gudang_items')->where('id', $itemId)->firstOrFail();
        $unit = $unitId === null ? null : $db->table('gudang_item_units')->where('id', $unitId)->where('item_id', $itemId)->first();
        if ($unitId !== null && $unit === null) {
            throw ValidationException::withMessages(['unit_id' => 'Satuan tidak sesuai dengan barang.']);
        }
        $input = StockLedger::milli($quantity);
        $factor = $unit === null ? 1000 : (int) $unit->factor_milli;
        if ($factor <= 0 || abs($input) > intdiv(PHP_INT_MAX, $factor)) {
            throw ValidationException::withMessages(['quantity' => 'Hasil konversi terlalu besar.']);
        }
        $product = $input * $factor;
        if ($product % 1000 !== 0) {
            throw ValidationException::withMessages(['quantity' => 'Hasil konversi harus tepat sampai tiga angka desimal satuan dasar.']);
        }
        if (abs(intdiv($product, 1000)) > 99999999999) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah satuan dasar melebihi batas delapan angka dan tiga desimal per baris dokumen.']);
        }

        return ['quantity_milli' => intdiv($product, 1000), 'input_quantity_milli' => $input,
            'input_unit' => $unit->name ?? $item->unit, 'factor_milli' => $factor];
    }
}
