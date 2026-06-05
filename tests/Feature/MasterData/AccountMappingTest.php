<?php

namespace Tests\Feature\MasterData;

class AccountMappingTest extends MasterDataTestCase
{
    public function test_sync_list_and_update_mapping_and_reject_wrong_account_type(): void
    {
        $ctx = $this->setUpTenant();

        // create accounts
        $ar = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '1100',
            'account_name' => 'Accounts Receivable',
            'account_type' => 'asset',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $rev = $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '4100',
            'account_name' => 'Revenue',
            'account_type' => 'revenue',
        ], $ctx['headers'])->assertStatus(201)->json('data');

        // list mappings (sync defaults)
        $list = $this->getJson('/api/master-data/account-mappings', $ctx['headers'])
            ->assertStatus(200)
            ->json('data');

        $this->assertNotEmpty($list);

        // valid mapping update
        $this->patchJson('/api/master-data/account-mappings/sales.accounts_receivable', [
            'account_id' => $ar['id'],
        ], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.account_id', $ar['id']);

        // invalid mapping update due to wrong account type
        $this->patchJson('/api/master-data/account-mappings/sales.accounts_receivable', [
            'account_id' => $rev['id'],
        ], $ctx['headers'])->assertStatus(422);
    }
}

