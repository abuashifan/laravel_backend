<?php

namespace Tests\Feature\CashBank;

use App\Models\Tenant\ChartOfAccount;
use Tests\Feature\Journal\JournalTestCase;

class CashBankAccountsTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_cash_bank_accounts(): void
    {
        auth()->logout();
        $this->getJson('/api/cash-bank/accounts')->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'finance');
        $this->getJson('/api/cash-bank/accounts')->assertStatus(422);
    }

    public function test_requires_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/cash-bank/accounts', $ctx['headers'])->assertStatus(403);
    }

    public function test_returns_only_cash_bank_accounts_and_can_include_inactive(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $inactiveCash = ChartOfAccount::query()->create([
            'account_code' => '1010',
            'account_name' => 'Petty Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => true,
            'is_active' => false,
            'is_system_default' => false,
        ]);

        $nonCash = ChartOfAccount::query()->create([
            'account_code' => '1200',
            'account_name' => 'Accounts Receivable',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $res = $this->getJson('/api/cash-bank/accounts', $ctx['headers']);
        $res->assertStatus(200);
        $res->assertJsonPath('data.include_inactive', false);

        $ids = collect($res->json('data.accounts'))->pluck('id')->all();
        $this->assertContains((int) $ctx['accounts']['debit'], $ids);
        $this->assertNotContains((int) $inactiveCash->id, $ids);
        $this->assertNotContains((int) $nonCash->id, $ids);

        $res2 = $this->getJson('/api/cash-bank/accounts?include_inactive=1', $ctx['headers']);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.include_inactive', true);

        $ids2 = collect($res2->json('data.accounts'))->pluck('id')->all();
        $this->assertContains((int) $inactiveCash->id, $ids2);
        $this->assertNotContains((int) $nonCash->id, $ids2);
    }
}

