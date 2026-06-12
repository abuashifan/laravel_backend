<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_code' => fake()->unique()->bothify('CT-####'),
            'name' => fake()->company(),
            'contact_type' => 'customer',
            'payment_term_id' => null,
            'receivable_account_id' => null,
            'payable_account_id' => null,
            'is_customer' => true,
            'is_supplier' => false,
            'is_employee' => false,
            'phone' => null,
            'email' => fake()->unique()->safeEmail(),
            'address' => null,
            'tax_number' => null,
            'notes' => null,
            'is_active' => true,
            'metadata' => null,
        ];
    }

    public function vendor(): static
    {
        return $this->state(fn (array $attributes): array => [
            'contact_type' => 'supplier',
            'is_customer' => false,
            'is_supplier' => true,
            'is_active' => true,
        ]);
    }

    public function customer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'contact_type' => 'customer',
            'is_customer' => true,
            'is_supplier' => false,
            'is_active' => true,
        ]);
    }
}
