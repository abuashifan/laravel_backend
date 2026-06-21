<?php

namespace Tests\Feature\MasterData;

use App\Models\Tenant\ChartOfAccount;

class ChartOfAccountTest extends MasterDataTestCase
{
    public function test_unauthenticated_cannot_list_coa(): void
    {
        $this->getJson('/api/master-data/chart-of-accounts')
            ->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant();

        $this->getJson('/api/master-data/chart-of-accounts')
            ->assertStatus(422)
            ->assertJsonPath('code', 'X_COMPANY_ID_REQUIRED');
    }

    public function test_user_can_create_asset_account_and_normal_balance_defaults(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '101',
            'account_name' => 'Cash',
            'account_type' => 'asset',
        ], $ctx['headers'])->assertStatus(201);

        $this->assertSame('debit', $res->json('data.normal_balance'));
    }

    public function test_is_cash_bank_true_allowed_for_asset_and_rejected_for_revenue(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '102',
            'account_name' => 'Bank',
            'account_type' => 'asset',
            'is_cash_bank' => true,
        ], $ctx['headers'])->assertStatus(201);

        $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '401',
            'account_name' => 'Sales Revenue',
            'account_type' => 'revenue',
            'is_cash_bank' => true,
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_duplicate_account_code_rejected(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '101',
            'account_name' => 'Cash',
            'account_type' => 'asset',
        ], $ctx['headers'])->assertStatus(201);

        $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '101',
            'account_name' => 'Cash 2',
            'account_type' => 'asset',
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_can_update_and_deactivate_account(): void
    {
        $ctx = $this->setUpTenant();

        $created = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '201',
            'account_name' => 'Accounts Payable',
            'account_type' => 'liability',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/master-data/chart-of-accounts/'.$created['id'], [
            'account_name' => 'AP',
        ], $ctx['headers'])->assertStatus(200)
            ->assertJsonPath('data.account_name', 'AP');

        $this->patchJson('/api/master-data/chart-of-accounts/'.$created['id'].'/deactivate', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_account_search_can_filter_multiple_active_account_types(): void
    {
        $ctx = $this->setUpTenant();

        foreach ([
            ['1100', 'Kas Aktif', 'asset', true],
            ['2100', 'Hutang Aktif', 'liability', true],
            ['4100', 'Pendapatan Aktif', 'revenue', true],
            ['1199', 'Kas Nonaktif', 'asset', false],
        ] as [$code, $name, $type, $active]) {
            $this->postJson('/api/master-data/chart-of-accounts', [
                'account_code' => $code,
                'account_name' => $name,
                'account_type' => $type,
            ], $ctx['headers'])->assertCreated();

            if (! $active) {
                $id = (int) ChartOfAccount::query()
                    ->where('account_code', $code)
                    ->value('id');
                $this->patchJson('/api/master-data/chart-of-accounts/'.$id.'/deactivate', [], $ctx['headers'])
                    ->assertOk();
            }
        }

        $response = $this->getJson(
            '/api/master-data/chart-of-accounts?page=1&per_page=10&is_active=1&account_types[]=asset&account_types[]=liability',
            $ctx['headers'],
        )->assertOk();

        $this->assertSame(
            ['Hutang Aktif', 'Kas Aktif'],
            collect($response->json('data.data'))->pluck('account_name')->sort()->values()->all(),
        );
    }
}
