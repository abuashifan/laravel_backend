<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\StockAdjustment;
use App\Models\Tenant\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockAdjustment>
 */
class StockAdjustmentFactory extends Factory
{
    protected $model = StockAdjustment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'adjustment_number' => fake()->unique()->bothify('SA-########'),
            'adjustment_date' => now()->toDateString(),
            'warehouse_id' => Warehouse::factory(),
            'status' => 'draft',
            'reason' => fake()->sentence(),
            'notes' => null,
            'internal_notes' => null,
            'stock_movement_id' => null,
            'revision_no' => 1,
            'created_by' => null,
            'updated_by' => null,
            'approved_by' => null,
            'posted_by' => null,
            'voided_by' => null,
            'approved_at' => null,
            'posted_at' => null,
            'voided_at' => null,
            'void_reason' => null,
            'metadata' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'draft',
            'approved_by' => null,
            'approved_at' => null,
            'posted_by' => null,
            'posted_at' => null,
        ]);
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'approved',
            'approved_at' => now(),
            'posted_by' => null,
            'posted_at' => null,
        ]);
    }
}
