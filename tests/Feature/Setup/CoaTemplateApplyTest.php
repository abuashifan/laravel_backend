<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\Journal\Models\JournalEntryLine;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use Tests\Feature\Journal\JournalTestCase;

class CoaTemplateApplyTest extends JournalTestCase
{
    public function test_routes_require_setup_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->getJson('/api/setup/coa-templates', $ctx['headers'])
            ->assertStatus(403);

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'gas_agent',
            'accounts' => [],
        ], $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_index_lists_templates_with_account_counts(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->getJson('/api/setup/coa-templates', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.0.id', 'gas_agent')
            ->assertJsonPath('data.4.id', 'blank')
            ->assertJsonPath('data.4.account_count', 0);
    }

    public function test_apply_creates_accounts_in_hierarchy_and_syncs_default_mappings(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $accounts = (array) config('coa_templates.templates.gas_agent.accounts');

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'gas_agent',
            'accounts' => $accounts,
        ], $ctx['headers'])
            ->assertOk();

        $kas = ChartOfAccount::query()->where('account_code', '1100')->firstOrFail();
        $this->assertTrue((bool) $kas->is_system_default);
        $this->assertSame('gas_agent', $kas->metadata['template_id'] ?? null);

        $persediaanGas = ChartOfAccount::query()->where('account_code', '1131')->firstOrFail();
        $persediaanHeader = ChartOfAccount::query()->where('account_code', '1130')->firstOrFail();
        $this->assertSame($persediaanHeader->id, $persediaanGas->parent_account_id);

        // AccountMappingStorageService::syncDefaultMappingsFromConfig() harus otomatis
        // mencocokkan kode akun template ke default_account_codes di account_mappings.php.
        $bank = ChartOfAccount::query()->where('account_code', '1110')->firstOrFail();
        $piutang = ChartOfAccount::query()->where('account_code', '1120')->firstOrFail();

        $this->assertSame($kas->id, AccountMapping::query()->where('mapping_key', 'cash_bank.default_cash')->value('account_id'));
        $this->assertSame($bank->id, AccountMapping::query()->where('mapping_key', 'cash_bank.default_bank')->value('account_id'));
        $this->assertSame($piutang->id, AccountMapping::query()->where('mapping_key', 'sales.accounts_receivable')->value('account_id'));
    }

    /**
     * Temuan Improvement #3/#4: tiap template wajib membawa blok aset tetap per
     * kategori (gedung, peralatan, kendaraan, software) lengkap dengan akumulasi
     * penyusutan dan beban penyusutannya, supaya pemetaan akun `fixed_assets.*`
     * terisi sendiri tanpa user membuat akun manual lebih dulu.
     */
    public function test_every_template_carries_the_per_category_fixed_asset_block(): void
    {
        $required = ['1500', '1510', '1511', '1520', '1521', '1530', '1531', '1590', '1600', '1601', '6171', '6172', '6173', '6175', '7200', '8200'];

        foreach ((array) config('coa_templates.templates') as $templateId => $template) {
            if ($templateId === 'blank') {
                continue;
            }

            $codes = array_column((array) $template['accounts'], 'code');
            foreach ($required as $code) {
                $this->assertContains($code, $codes, "Template [{$templateId}] tidak punya akun aset tetap [{$code}].");
            }
        }
    }

    /**
     * Akumulasi penyusutan/amortisasi bertipe `asset` tetapi saldo normalnya
     * `credit` (kontra-aset). Tanpa `normal_balance` eksplisit di template,
     * ChartOfAccountService menurunkannya dari tipe dan akun ini lahir dengan
     * saldo normal `debit` -- neraca lalu menambah, bukan mengurangi.
     */
    public function test_apply_keeps_contra_asset_accounts_on_credit_normal_balance(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'trading',
            'accounts' => (array) config('coa_templates.templates.trading.accounts'),
        ], $ctx['headers'])->assertOk();

        foreach (['1511', '1521', '1531', '1601'] as $code) {
            $account = ChartOfAccount::query()->where('account_code', $code)->firstOrFail();
            $this->assertSame('asset', $account->account_type, "Akun {$code} harus bertipe asset.");
            $this->assertSame('credit', $account->normal_balance, "Akun kontra-aset {$code} harus bersaldo normal credit.");
        }

        // Akun aset tetap biasa tetap debit.
        $this->assertSame('debit', ChartOfAccount::query()->where('account_code', '1520')->value('normal_balance'));
    }

    public function test_apply_defaults_every_required_fixed_asset_mapping(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'trading',
            'accounts' => (array) config('coa_templates.templates.trading.accounts'),
        ], $ctx['headers'])->assertOk();

        $missing = AccountMapping::query()
            ->where('module', 'fixed_assets')
            ->where('is_required', true)
            ->whereNull('account_id')
            ->pluck('mapping_key')
            ->all();

        $this->assertSame([], $missing, 'Pemetaan akun aset tetap wajib masih kosong: '.implode(', ', $missing));
    }

    public function test_reapplying_template_replaces_previous_system_default_accounts_only(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $manual = ChartOfAccount::query()->create([
            'account_code' => '9999',
            'account_name' => 'Akun Manual User',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'gas_agent',
            'accounts' => (array) config('coa_templates.templates.gas_agent.accounts'),
        ], $ctx['headers'])->assertOk();

        $this->assertTrue(ChartOfAccount::query()->where('account_code', '1100')->exists());

        // Ganti ke template lain yang tidak punya kode 1131/1132 (khusus gas_agent).
        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'service',
            'accounts' => (array) config('coa_templates.templates.service.accounts'),
        ], $ctx['headers'])->assertOk();

        $this->assertFalse(ChartOfAccount::query()->where('account_code', '1131')->exists());
        $this->assertTrue(ChartOfAccount::query()->where('account_code', '1100')->exists());
        $this->assertTrue(ChartOfAccount::query()->whereKey($manual->id)->exists(), 'Akun manual user tidak boleh ikut terhapus.');
    }

    public function test_apply_rejected_when_previous_template_accounts_are_referenced_by_journal(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'gas_agent',
            'accounts' => (array) config('coa_templates.templates.gas_agent.accounts'),
        ], $ctx['headers'])->assertOk();

        $kas = ChartOfAccount::query()->where('account_code', '1100')->firstOrFail();

        $journal = JournalEntry::query()->create([
            'journal_number' => 'JV-COA-TEMPLATE-001',
            'journal_date' => '2026-01-01',
            'description' => 'Transaksi memakai akun template',
            'status' => 'posted',
            'total_debit' => 1000,
            'total_credit' => 1000,
        ]);
        JournalEntryLine::query()->create([
            'journal_entry_id' => $journal->id,
            'account_id' => $kas->id,
            'debit' => 1000,
            'credit' => 0,
            'line_order' => 1,
        ]);

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'trading',
            'accounts' => (array) config('coa_templates.templates.trading.accounts'),
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COA_TEMPLATE_ACCOUNTS_IN_USE');

        // Rollback penuh: akun asli tetap ada, tidak ada akun template baru yang terbentuk.
        $this->assertTrue(ChartOfAccount::query()->whereKey($kas->id)->exists());
        $this->assertFalse(ChartOfAccount::query()->where('account_code', '1131')->where('metadata->template_id', 'trading')->exists());
    }

    public function test_apply_rejects_child_row_whose_parent_code_appears_later(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'blank',
            'accounts' => [
                ['code' => '1130', 'name' => 'Persediaan', 'type' => 'asset', 'parent_code' => '1000'],
                ['code' => '1000', 'name' => 'AKTIVA LANCAR', 'type' => 'asset', 'parent_code' => null],
            ],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COA_TEMPLATE_PARENT_NOT_FOUND');
    }

    public function test_apply_rejects_unknown_parent_code_in_payload(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'blank',
            'accounts' => [
                ['code' => '1130', 'name' => 'Persediaan', 'type' => 'asset', 'parent_code' => '9000'],
            ],
        ], $ctx['headers'])
            ->assertStatus(422);
    }
}
