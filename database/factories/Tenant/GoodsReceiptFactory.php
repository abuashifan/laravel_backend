<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Contact;
use App\Models\Tenant\GoodsReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceipt>
 */
class GoodsReceiptFactory extends Factory
{
    protected $model = GoodsReceipt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'receipt_number' => fake()->unique()->bothify('GR-########'),
            'receipt_date' => now()->toDateString(),
            'vendor_id' => Contact::factory()->vendor(),
            'purchase_order_id' => null,
            'status' => 'draft',
            'notes' => null,
            'internal_notes' => null,
            'metadata' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'draft',
            'received_at' => null,
            'voided_at' => null,
        ]);
    }
}
