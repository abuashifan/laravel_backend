<?php

namespace Tests\Feature\MasterData;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;

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
        $this->assertSame('Uang Muka Penjualan', $depositMapping['label'] ?? null);
        $this->assertTrue((bool) ($depositMapping['is_required'] ?? false));
        $this->assertSame(['liability'], $depositMapping['account_types'] ?? []);
        $this->assertTrue((bool) ($depositMapping['visible_in_settings'] ?? false));
        $vendorDepositMapping = collect($list)->firstWhere('mapping_key', 'purchase.vendor_deposit');
        $this->assertSame('Uang Muka Pembelian', $vendorDepositMapping['label'] ?? null);
        $this->assertTrue((bool) ($vendorDepositMapping['is_required'] ?? false));
        $this->assertSame(['asset'], $vendorDepositMapping['account_types'] ?? []);
        $this->assertTrue((bool) ($vendorDepositMapping['visible_in_settings'] ?? false));
        $this->assertFalse((bool) (collect($list)->firstWhere('mapping_key', 'journal.suspense')['visible_in_settings'] ?? true));

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

    public function test_sync_default_mappings_binds_customer_deposit_to_existing_default_account(): void
    {
        $ctx = $this->setUpTenant();
        $deposit = ChartOfAccount::query()->create([
            'account_code' => '2130',
            'account_name' => 'Uang Muka Pelanggan',
            'account_type' => 'liability',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        $this->getJson('/api/master-data/account-mappings', $ctx['headers'])->assertStatus(200);

        $this->assertSame(
            (int) $deposit->id,
            (int) AccountMapping::query()->where('mapping_key', 'sales.customer_deposit')->value('account_id')
        );
    }

    public function test_required_mapping_cannot_be_cleared_and_bulk_update_is_atomic(): void
    {
        $ctx = $this->setUpTenant();
        $asset = ChartOfAccount::query()->create([
            'account_code' => '1100',
            'account_name' => 'Piutang Usaha',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
        ]);
        $revenue = ChartOfAccount::query()->create([
            'account_code' => '4100',
            'account_name' => 'Pendapatan',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_active' => true,
        ]);

        $this->getJson('/api/master-data/account-mappings', $ctx['headers'])->assertOk();

        $this->patchJson('/api/master-data/account-mappings/sales.accounts_receivable', [
            'account_id' => null,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'REQUIRED_MAPPING_EMPTY');

        $this->patchJson('/api/master-data/account-mappings', [
            'mappings' => [
                ['mapping_key' => 'sales.accounts_receivable', 'account_id' => $asset->id],
                ['mapping_key' => 'sales.customer_deposit', 'account_id' => $revenue->id],
            ],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_TYPE_NOT_ALLOWED');

        $this->assertNotSame(
            $asset->id,
            AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'),
        );

        $this->patchJson('/api/master-data/account-mappings', [
            'mappings' => [
                ['mapping_key' => 'sales.accounts_receivable', 'account_id' => $asset->id],
            ],
        ], $ctx['headers'])->assertOk();

        $this->assertSame(
            $asset->id,
            AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'),
        );
    }
}
