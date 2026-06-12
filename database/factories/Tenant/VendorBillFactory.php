<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Contact;
use App\Models\Tenant\VendorBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorBill>
 */
class VendorBillFactory extends Factory
{
    protected $model = VendorBill::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bill_number' => fake()->unique()->bothify('VB-########'),
            'bill_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'vendor_id' => Contact::factory()->vendor(),
            'status' => 'draft',
            'subtotal_before_discount' => 0,
            'line_discount_total' => 0,
            'header_discount_type' => null,
            'header_discount_value' => 0,
            'header_discount_amount' => 0,
            'subtotal_after_discount' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
            'applied_vendor_deposit_amount' => 0,
            'paid_amount' => 0,
            'balance_due' => 0,
            'journal_entry_id' => null,
            'ap_account_id' => null,
            'metadata' => null,
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'draft',
            'posted_at' => null,
            'voided_at' => null,
        ]);
    }
}
