<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountMapping>
 */
class AccountMappingFactory extends Factory
{
    protected $model = AccountMapping::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mapping_key' => fake()->unique()->bothify('test.mapping.####'),
            'module' => 'test',
            'account_id' => ChartOfAccount::factory(),
            'is_required' => true,
            'is_active' => true,
            'metadata' => null,
        ];
    }
}
