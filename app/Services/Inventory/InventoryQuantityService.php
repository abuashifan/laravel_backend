<?php

namespace App\Services\Inventory;

use App\Exceptions\ApiException;

class InventoryQuantityService
{
    public function __construct(private readonly InventoryConfigService $config)
    {
    }

    public function normalizeQuantity(float|int|string $qty): float
    {
        $precision = $this->config->stockPrecision();
        return round((float) $qty, $precision);
    }

    public function assertPositiveQuantity(float|int|string $qty): void
    {
        $q = $this->normalizeQuantity($qty);
        if ($q <= 0) {
            throw ApiException::make('QUANTITY_MUST_BE_POSITIVE', 'Quantity must be greater than zero.', 422);
        }
    }

    public function assertNonNegativeQuantity(float|int|string $qty): void
    {
        $q = $this->normalizeQuantity($qty);
        if ($q < 0) {
            throw ApiException::make('QUANTITY_MUST_BE_NON_NEGATIVE', 'Quantity must be non-negative.', 422);
        }
    }

    public function calculateRemainingQuantity(float|int|string $orderedQty, float|int|string $movedQty): float
    {
        $ordered = $this->normalizeQuantity($orderedQty);
        $moved = $this->normalizeQuantity($movedQty);
        return $this->normalizeQuantity($ordered - $moved);
    }

    public function assertDoesNotExceedRemaining(float|int|string $qty, float|int|string $remainingQty): void
    {
        $q = $this->normalizeQuantity($qty);
        $remaining = $this->normalizeQuantity($remainingQty);
        if ($q - $remaining > 0.0000001) {
            throw ApiException::make('QUANTITY_EXCEEDS_REMAINING', 'Quantity exceeds remaining quantity.', 422, [
                'quantity' => ['Quantity exceeds remaining quantity.'],
            ], [
                'remaining' => $remaining,
            ]);
        }
    }
}

