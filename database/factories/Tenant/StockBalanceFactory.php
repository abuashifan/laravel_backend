<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Product;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockBalance>
 */
class StockBalanceFactory extends Factory
{
    protected $model = StockBalance::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->stockItem(),
            'warehouse_id' => Warehouse::factory(),
            'quantity_on_hand' => 0,
            'quantity_reserved' => 0,
            'quantity_available' => 0,
            'average_cost' => 0,
            'total_value' => 0,
            'last_movement_id' => null,
            'last_movement_at' => null,
            'metadata' => null,
        ];
    }
}
