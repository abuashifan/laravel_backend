<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\StockOpname;
use App\Models\Tenant\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockOpname>
 */
class StockOpnameFactory extends Factory
{
    protected $model = StockOpname::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opname_number' => fake()->unique()->bothify('SO-########'),
            'opname_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'draft',
            'counted_at' => null,
            'finalized_at' => null,
            'stock_movement_id' => null,
            'notes' => null,
            'internal_notes' => null,
            'created_by' => null,
            'updated_by' => null,
            'counted_by' => null,
            'finalized_by' => null,
            'voided_by' => null,
            'voided_at' => null,
            'void_reason' => null,
            'metadata' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'draft',
            'counted_by' => null,
            'counted_at' => null,
            'finalized_by' => null,
            'finalized_at' => null,
        ]);
    }

    public function counted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'counted',
            'counted_at' => now(),
            'finalized_by' => null,
            'finalized_at' => null,
        ]);
    }
}
