<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => fake()->unique()->bothify('WH-###'),
            'name' => fake()->company().' Warehouse',
            'address' => null,
            'is_default' => false,
            'is_active' => true,
            'metadata' => null,
        ];
    }
}
