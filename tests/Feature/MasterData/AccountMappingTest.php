<?php

namespace Tests\Feature\MasterData;

use App\Models\Tenant\AccountMapping;

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
        $depositMapping = collect($list)->firstWhere('mapping_key', 'sales.customer_deposit');
        $this->assertSame('Uang Muka Pelanggan', $depositMapping['label'] ?? null);
        $this->assertTrue((bool) ($depositMapping['is_required'] ?? false));
        $this->assertSame(['liability'], $depositMapping['account_types'] ?? []);
        $vendorDepositMapping = collect($list)->firstWhere('mapping_key', 'purchase.vendor_deposit');
        $this->assertSame('Uang Muka Pemasok', $vendorDepositMapping['label'] ?? null);
        $this->assertTrue((bool) ($vendorDepositMapping['is_required'] ?? false));
        $this->assertSame(['asset'], $vendorDepositMapping['account_types'] ?? []);

        // valid mapping update
        $this->patchJson('/api/master-data/account-mappings/sales.accounts_receivable', [
            'account_id' => $ar['id'],
        ], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.account_id', $ar['id']);

        $this->getJson('/api/master-data/account-mappings', $ctx['headers'])->assertStatus(200);
        $this->assertSame($ar['id'], AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));

        // invalid mapping update due to wrong account type
        $this->patchJson('/api/master-data/account-mappings/sales.accounts_receivable', [
            'account_id' => $rev['id'],
        ], $ctx['headers'])->assertStatus(422);
    }
}
