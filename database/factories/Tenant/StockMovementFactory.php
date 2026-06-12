<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'movement_number' => fake()->unique()->bothify('SM-########'),
            'movement_date' => now()->toDateString(),
            'movement_type' => 'adjustment_in',
            'direction' => 'in',
            'status' => 'draft',
            'source_type' => null,
            'source_id' => null,
            'source_number' => null,
            'source_revision' => null,
            'warehouse_id' => null,
            'description' => null,
            'notes' => null,
            'internal_notes' => null,
            'total_quantity' => 0,
            'total_value' => 0,
            'journal_entry_id' => null,
            'reversal_of_id' => null,
            'reversed_by_id' => null,
            'revision_no' => 1,
            'created_by' => null,
            'updated_by' => null,
            'posted_by' => null,
            'voided_by' => null,
            'posted_at' => null,
            'voided_at' => null,
            'void_reason' => null,
            'metadata' => null,
        ];
    }

    public function adjustmentIn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'movement_type' => 'adjustment_in',
            'direction' => 'in',
            'status' => 'draft',
        ]);
    }
}
