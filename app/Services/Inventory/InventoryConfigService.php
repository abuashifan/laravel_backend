<?php

namespace App\Services\Inventory;

class InventoryConfigService
{
    public function valuationMethod(): string
    {
        return (string) config('inventory.valuation_method', 'moving_average');
    }

    public function allowNegativeStock(): bool
    {
        return (bool) config('inventory.allow_negative_stock', false);
    }

    public function stockPrecision(): int
    {
        return (int) config('inventory.stock_precision', 4);
    }

    public function costPrecision(): int
    {
        return (int) config('inventory.cost_precision', 6);
    }

    public function amountPrecision(): int
    {
        return (int) config('inventory.amount_precision', 2);
    }
}

