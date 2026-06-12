<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_code' => fake()->unique()->bothify('SKU-####'),
            'product_name' => fake()->words(3, true),
            'product_type' => 'goods',
            'product_category_id' => null,
            'unit_id' => null,
            'is_stock_item' => false,
            'is_active' => true,
            'description' => null,
            'metadata' => null,
            'sales_account_id' => null,
            'purchase_account_id' => null,
            'inventory_account_id' => null,
            'cogs_account_id' => null,
        ];
    }

    public function stockItem(): static
    {
        return $this->state(fn (array $attributes): array => [
            'product_type' => 'goods',
            'is_stock_item' => true,
            'is_active' => true,
        ]);
    }
}
