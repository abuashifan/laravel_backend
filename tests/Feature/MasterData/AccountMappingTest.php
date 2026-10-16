<?php

namespace Tests\Feature\MasterData;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;

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

        // invalid mapping update: akun induk (AR baru punya anak) tidak boleh
        // dipakai -- akun mapping harus akun anak (leaf).
        $this->postJson('/api/master-data/chart-of-accounts', [
            'account_code' => '1100.01',
            'account_name' => 'Accounts Receivable - Sub',
            'account_type' => 'asset',
            'parent_account_id' => $ar['id'],
        ], $ctx['headers'])->assertStatus(201);

        $this->patchJson('/api/master-data/account-mappings/sales.accounts_receivable', [
            'account_id' => $ar['id'],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ACCOUNT_NOT_POSTABLE');
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

    public function test_reset_defaults_overwrites_filled_mappings_with_rescanned_coa_defaults(): void
    {
        $ctx = $this->setUpTenant();

        $make = fn (string $code, string $name, string $type) => ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit',
            'is_active' => true,
        ]);

        $defaultAr = $make('1120', 'Piutang Usaha', 'asset');
        $otherAr = $make('11211', 'Piutang Lama', 'asset');
        $make('4100', 'Penjualan', 'revenue');

        $this->getJson('/api/master-data/account-mappings', $ctx['headers'])->assertStatus(200);

        // Pemetaan diarahkan manual ke akun non-default -- sync biasa tidak menyentuhnya.
        $this->patchJson('/api/master-data/account-mappings/sales.accounts_receivable', [
            'account_id' => $otherAr->id,
        ], $ctx['headers'])->assertStatus(200);
        $this->getJson('/api/master-data/account-mappings', $ctx['headers'])->assertStatus(200);
        $this->assertSame((int) $otherAr->id, (int) AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));

        $response = $this->postJson('/api/master-data/account-mappings/reset-defaults', [], $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame((int) $defaultAr->id, (int) AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));
        $this->assertSame(
            (int) $defaultAr->id,
            (int) collect($response->json('data.mappings'))->firstWhere('mapping_key', 'sales.accounts_receivable')['account_id']
        );
        $this->assertGreaterThanOrEqual(2, $response->json('data.summary.filled'));
        // Key wajib tanpa akun default di COA dikosongkan dan dilaporkan.
        $this->assertContains('purchase.accounts_payable', $response->json('data.summary.missing_required'));
        $this->assertNull(AccountMapping::query()->where('mapping_key', 'purchase.accounts_payable')->value('account_id'));
    }

    public function test_reset_defaults_requires_settings_edit_permission(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/master-data/account-mappings/reset-defaults', [], $ctx['headers'])
            ->assertStatus(403);
    }
}
