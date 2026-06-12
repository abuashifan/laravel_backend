<?php

namespace Tests\Feature\Accounting;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\TenantTestCase;

class AccountMappingHealthTest extends TenantTestCase
{
    protected function tenantRole(): string
    {
        return 'owner';
    }

    public function test_all_required_mappings_present_returns_healthy(): void
    {
        $this->seedRequiredMappings();

        $this->getJson('/api/v1/accounting/account-mapping-health', $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.summary.required_missing', 0)
            ->assertJsonPath('data.summary.conditional_missing', 0);
    }

    public function test_required_mapping_with_null_account_returns_critical(): void
    {
        $this->seedRequiredMappings();
        AccountMapping::query()
            ->where('mapping_key', AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE)
            ->update(['account_id' => null]);

        $this->getJson('/api/v1/accounting/account-mapping-health', $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'critical')
            ->assertJsonPath('data.required_missing.0.key', AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE);
    }

    public function test_conditional_mapping_with_null_account_returns_warning(): void
    {
        $this->seedRequiredMappings();
        $this->createMapping(AccountMappingKey::PURCHASE_INVENTORY_INTERIM, null, false);

        $this->getJson('/api/v1/accounting/account-mapping-health', $this->headers)
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'warning')
            ->assertJsonPath('data.conditional_missing.0.key', AccountMappingKey::PURCHASE_INVENTORY_INTERIM)
            ->assertJsonPath('data.conditional_missing.0.workflow', 'goods receipt workflow');
    }

    public function test_response_structure_matches_health_contract(): void
    {
        $this->seedRequiredMappings();

        $response = $this->getJson('/api/v1/accounting/account-mapping-health', $this->headers)
            ->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'status',
                    'summary' => ['total', 'healthy', 'required_missing', 'conditional_missing'],
                    'required_missing',
                    'conditional_missing',
                    'healthy' => [
                        '*' => ['key', 'workflow', 'account_id', 'account_name'],
                    ],
                ],
                'meta',
            ]);

        $this->assertIsArray($response->json('data.required_missing'));
        $this->assertIsArray($response->json('data.conditional_missing'));
        $this->assertIsArray($response->json('data.healthy'));
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/accounting/account-mapping-health')
            ->assertStatus(401);
    }

    private function seedRequiredMappings(): void
    {
        foreach ((array) config('account_mappings.required_mappings', []) as $key => $config) {
            if (! (bool) ($config['required'] ?? false)) {
                continue;
            }

            $accountTypes = (array) ($config['account_types'] ?? ['asset']);
            $this->createMapping((string) $key, $this->accountForType((string) ($accountTypes[0] ?? 'asset')), true);
        }
    }

    private function createMapping(string $key, ?int $accountId, bool $required): void
    {
        AccountMapping::query()->updateOrCreate(
            ['mapping_key' => $key],
            [
                'module' => explode('.', $key, 2)[0],
                'account_id' => $accountId,
                'is_required' => $required,
                'is_active' => true,
            ]
        );
    }

    private function accountForType(string $type): int
    {
        $normalBalance = in_array($type, ['liability', 'equity', 'revenue'], true) ? 'credit' : 'debit';

        return (int) ChartOfAccount::query()->create([
            'account_code' => 'H'.str_pad((string) (ChartOfAccount::query()->count() + 1), 5, '0', STR_PAD_LEFT),
            'account_name' => str($type)->headline()->append(' Account')->toString(),
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ])->id;
    }
}
