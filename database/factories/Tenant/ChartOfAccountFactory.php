<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartOfAccount>
 */
class ChartOfAccountFactory extends Factory
{
    protected $model = ChartOfAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_code' => fake()->unique()->numerify('####'),
            'account_name' => fake()->words(2, true),
            'account_type' => 'asset',
            'parent_account_id' => null,
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
            'description' => null,
            'metadata' => null,
        ];
    }

    public function asset(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_type' => 'asset',
            'normal_balance' => 'debit',
        ]);
    }

    public function liability(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_type' => 'liability',
            'normal_balance' => 'credit',
        ]);
    }

    public function equity(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_type' => 'equity',
            'normal_balance' => 'credit',
        ]);
    }

    public function revenue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes): array => [
            'account_type' => 'expense',
            'normal_balance' => 'debit',
        ]);
    }

    public function cashBank(): static
    {
        return $this->asset()->state(fn (array $attributes): array => [
            'is_cash_bank' => true,
        ]);
    }
}
