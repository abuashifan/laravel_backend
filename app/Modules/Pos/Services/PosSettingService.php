<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosSetting;

class PosSettingService
{
    /** Nilai bawaan; kunci di luar daftar ini ditolak saat disimpan. */
    public const DEFAULTS = [
        'store_name' => '',
        'store_address' => '',
        'store_phone' => '',
        'receipt_footer' => 'Terima kasih sudah berbelanja.',
        // none | digital | print -- mode struk yang dipilih otomatis di kasir.
        'default_receipt_mode' => 'digital',
        // '1' = penjualan boleh membuat stok minus.
        'allow_negative_stock' => '0',
        // Lebar kertas printer thermal: 58 atau 80 (mm).
        'paper_width' => '58',
        // Pajak per toko: none | exclusive (ditambahkan ke total) | inclusive (sudah di harga).
        'tax_mode' => 'none',
        'tax_rate' => '11',
        'tax_name' => 'PPN',
        // Pembulatan total: kelipatan 0 (mati) | 100 | 500 | 1000; nearest | down | up.
        'rounding_unit' => '0',
        'rounding_mode' => 'nearest',
    ];

    /** @return array<string,string> */
    public function all(): array
    {
        $stored = PosSetting::query()->pluck('value', 'key')->all();

        return array_merge(self::DEFAULTS, array_intersect_key(
            array_map(fn ($value) => (string) $value, $stored),
            self::DEFAULTS,
        ));
    }

    public function get(string $key): string
    {
        return $this->all()[$key] ?? '';
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,string>
     */
    public function update(array $values): array
    {
        foreach (array_intersect_key($values, self::DEFAULTS) as $key => $value) {
            PosSetting::query()->updateOrCreate(['key' => $key], ['value' => (string) ($value ?? '')]);
        }

        return $this->all();
    }

    public function allowsNegativeStock(): bool
    {
        return $this->get('allow_negative_stock') === '1';
    }
}
