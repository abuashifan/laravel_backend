<?php

namespace App\Modules\Pos\Services;

use App\Modules\Pos\Models\PosProduct;

/**
 * Hitungan harga kasir: harga grosir, pajak toko, pembulatan total.
 * Aplikasi kasir meniru rumus ini (`finlite_pos/src/lib/pricing.ts`) hanya
 * untuk tampilan; angka yang disimpan selalu hasil hitungan di sini.
 */
class PosPricingService
{
    public const TAX_MODES = ['none', 'exclusive', 'inclusive'];

    public const ROUNDING_UNITS = ['0', '100', '500', '1000'];

    public const ROUNDING_MODES = ['nearest', 'down', 'up'];

    public function __construct(private readonly PosSettingService $settings) {}

    /** Harga satuan untuk jumlah beli: tingkat grosir tertinggi yang terpenuhi, kalau tidak harga biasa. */
    public function unitPrice(PosProduct $product, float $quantity): float
    {
        $price = (float) $product->price;
        foreach (self::normalizeTiers($product->price_tiers) as $tier) {
            if ($quantity + 0.0001 >= $tier['min_quantity']) {
                $price = $tier['price'];
            }
        }

        return round($price, 2);
    }

    /**
     * Pajak dan pembulatan dari dasar pengenaan (subtotal - diskon total).
     *
     * @return array{tax_amount: float, tax_inclusive: bool, tax_rate: float, rounding_amount: float, total: float}
     */
    public function totals(float $base): array
    {
        $all = $this->settings->all();
        $mode = in_array($all['tax_mode'], self::TAX_MODES, true) ? $all['tax_mode'] : 'none';
        $rate = $mode === 'none' ? 0.0 : max(0.0, (float) $all['tax_rate']);

        $tax = match (true) {
            $rate <= 0 => 0.0,
            $mode === 'exclusive' => round($base * $rate / 100),
            default => round($base * $rate / (100 + $rate)),
        };
        $beforeRounding = round($base + ($mode === 'exclusive' ? $tax : 0.0), 2);
        $total = self::round($beforeRounding, (int) $all['rounding_unit'], $all['rounding_mode']);

        return [
            'tax_amount' => $tax,
            'tax_inclusive' => $mode === 'inclusive' && $rate > 0,
            'tax_rate' => $rate,
            'rounding_amount' => round($total - $beforeRounding, 2),
            'total' => $total,
        ];
    }

    public static function round(float $amount, int $unit, string $mode): float
    {
        if ($unit <= 0) {
            return round($amount, 2);
        }
        $steps = $amount / $unit;

        return (float) match ($mode) {
            'down' => floor($steps + 1e-9),
            'up' => ceil($steps - 1e-9),
            default => round($steps),
        } * $unit;
    }

    /**
     * Rapikan isian harga grosir: buang baris kosong, urutkan menurut jumlah
     * minimum, satu harga per jumlah minimum.
     *
     * @return list<array{min_quantity: float, price: float}>
     */
    public static function normalizeTiers(mixed $tiers): array
    {
        $byMin = [];
        foreach (is_array($tiers) ? $tiers : [] as $tier) {
            $min = round((float) ($tier['min_quantity'] ?? 0), 3);
            if ($min <= 1 || ! isset($tier['price'])) {
                continue;
            }
            $byMin[(string) $min] = ['min_quantity' => $min, 'price' => round((float) $tier['price'], 2)];
        }
        usort($byMin, fn ($a, $b) => $a['min_quantity'] <=> $b['min_quantity']);

        return array_values($byMin);
    }
}
