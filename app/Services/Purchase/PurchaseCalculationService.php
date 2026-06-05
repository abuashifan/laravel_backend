<?php

namespace App\Services\Purchase;

use InvalidArgumentException;

class PurchaseCalculationService
{
    public function calculateLine(array $line): array
    {
        $precision = $this->precision($line);
        $quantity = (float) ($line['quantity'] ?? 0);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        $taxRate = (float) ($line['tax_rate'] ?? 0);
        $grossAmount = $this->round($quantity * $unitPrice, $precision);
        $discountAmount = $this->calculateDiscountAmount(
            $line['discount_type'] ?? null,
            $line['discount_value'] ?? null,
            $grossAmount,
            $precision
        );

        $subtotalAfterDiscount = $this->round($grossAmount - $discountAmount, $precision);
        $taxAmount = $this->round($subtotalAfterDiscount * ($taxRate / 100), $precision);
        $lineTotal = $this->round($subtotalAfterDiscount + $taxAmount, $precision);

        $calculated = array_merge($line, [
            'gross_amount' => $grossAmount,
            'discount_amount' => $discountAmount,
            'subtotal_after_discount' => $subtotalAfterDiscount,
            'tax_amount' => $taxAmount,
            'line_total' => $lineTotal,
        ]);

        unset($calculated['amount_precision']);

        return $calculated;
    }

    public function calculateDocument(array $lines, array $header = []): array
    {
        $precision = $this->precision($header);
        $calculatedLines = array_map(fn (array $line): array => $this->calculateLine(array_merge(
            ['amount_precision' => $precision],
            $line
        )), $lines);

        $subtotalBeforeDiscount = $this->sum($calculatedLines, 'gross_amount', $precision);
        $lineDiscountTotal = $this->sum($calculatedLines, 'discount_amount', $precision);
        $subtotalAfterLineDiscount = $this->round($subtotalBeforeDiscount - $lineDiscountTotal, $precision);
        $headerDiscountAmount = $this->calculateDiscountAmount(
            $header['header_discount_type'] ?? null,
            $header['header_discount_value'] ?? null,
            $subtotalAfterLineDiscount,
            $precision
        );
        $subtotalAfterDiscount = $this->round($subtotalAfterLineDiscount - $headerDiscountAmount, $precision);
        $lineTaxBase = $this->sum($calculatedLines, 'subtotal_after_discount', $precision);
        $lineTaxTotal = $this->sum($calculatedLines, 'tax_amount', $precision);
        $taxTotal = $lineTaxBase > 0
            ? $this->round($lineTaxTotal * ($subtotalAfterDiscount / $lineTaxBase), $precision)
            : 0.0;
        $grandTotal = $this->round($subtotalAfterDiscount + $taxTotal, $precision);

        return [
            'lines' => $calculatedLines,
            'subtotal_before_discount' => $subtotalBeforeDiscount,
            'line_discount_total' => $lineDiscountTotal,
            'header_discount_type' => $header['header_discount_type'] ?? null,
            'header_discount_value' => (float) ($header['header_discount_value'] ?? 0),
            'header_discount_amount' => $headerDiscountAmount,
            'subtotal_after_discount' => $subtotalAfterDiscount,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
        ];
    }

    public function calculateDiscountAmount(
        ?string $type,
        float|int|null $value,
        float|int $base,
        int $precision = 2
    ): float {
        $base = (float) $base;
        $value = (float) ($value ?? 0);

        if ($type === null || $type === '') {
            return 0.0;
        }

        if ($type === 'percent') {
            if ($value < 0 || $value > 100) {
                throw new InvalidArgumentException('DISCOUNT_PERCENT_OUT_OF_RANGE');
            }

            $amount = $base * ($value / 100);
        } elseif ($type === 'fixed_amount') {
            if ($value < 0) {
                throw new InvalidArgumentException('DISCOUNT_AMOUNT_NEGATIVE');
            }

            $amount = $value;
        } else {
            throw new InvalidArgumentException('UNKNOWN_DISCOUNT_TYPE');
        }

        $amount = $this->round($amount, $precision);
        $this->validateDiscountDoesNotExceedBase($amount, $base);

        return $amount;
    }

    public function validateDiscountDoesNotExceedBase(float|int $discountAmount, float|int $base): void
    {
        if ((float) $discountAmount > (float) $base) {
            throw new InvalidArgumentException('DISCOUNT_EXCEEDS_BASE');
        }
    }

    private function precision(array $data): int
    {
        return (int) ($data['amount_precision'] ?? 2);
    }

    private function sum(array $rows, string $key, int $precision): float
    {
        return $this->round(array_reduce(
            $rows,
            fn (float $carry, array $row): float => $carry + (float) ($row[$key] ?? 0),
            0.0
        ), $precision);
    }

    private function round(float|int $amount, int $precision): float
    {
        return round((float) $amount, $precision, PHP_ROUND_HALF_UP);
    }
}
