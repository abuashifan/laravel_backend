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

    /**
     * Aset tetap tidak boleh kembali menjadi satu akun gabungan: tiap kelas
     * (kendaraan, gedung, peralatan, software) wajib punya akun harga perolehan,
     * akumulasi penyusutan/amortisasi, dan beban sendiri di setiap template.
     */
    public function test_every_template_splits_fixed_asset_accounts_per_class(): void
    {
        $expected = [
            '1510' => 'asset', '1511' => 'asset', '6170' => 'expense',
            '1520' => 'asset', '1521' => 'asset', '6171' => 'expense',
            '1530' => 'asset', '1531' => 'asset', '6172' => 'expense',
            '1540' => 'asset', '1541' => 'asset', '6175' => 'expense',
        ];

        foreach ((array) config('coa_templates.templates') as $templateId => $template) {
            $accounts = (array) ($template['accounts'] ?? []);
            if ($accounts === []) {
                continue; // template 'blank' memang tanpa akun
            }

            $byCode = [];
            foreach ($accounts as $row) {
                $byCode[(string) $row['code']] = $row;
            }

            foreach ($expected as $code => $type) {
                $this->assertArrayHasKey($code, $byCode, "Template [{$templateId}] kehilangan akun [{$code}].");
                $this->assertSame($type, (string) $byCode[$code]['type'], "Akun [{$code}] di template [{$templateId}] salah tipe.");
            }

            $this->assertArrayNotHasKey(
                'Akumulasi Penyusutan Aset Tetap',
                array_flip(array_column($accounts, 'name')),
                "Template [{$templateId}] masih memakai akun akumulasi penyusutan gabungan."
            );
        }
    }

    public function test_apply_maps_each_fixed_asset_class_to_its_own_accounts(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->postJson('/api/setup/coa-templates/apply', [
            'template_id' => 'trading',
            'accounts' => (array) config('coa_templates.templates.trading.accounts'),
        ], $ctx['headers'])->assertOk();

        $expected = [
            'fixed_assets.vehicle_cost' => '1510',
            'fixed_assets.vehicle_accumulated_depreciation' => '1511',
            'fixed_assets.vehicle_depreciation_expense' => '6170',
            'fixed_assets.building_cost' => '1520',
            'fixed_assets.building_accumulated_depreciation' => '1521',
            'fixed_assets.building_depreciation_expense' => '6171',
            'fixed_assets.equipment_cost' => '1530',
            'fixed_assets.equipment_accumulated_depreciation' => '1531',
            'fixed_assets.equipment_depreciation_expense' => '6172',
            'fixed_assets.software_cost' => '1540',
            'fixed_assets.software_accumulated_amortization' => '1541',
            'fixed_assets.software_amortization_expense' => '6175',
        ];

        foreach ($expected as $key => $code) {
            $accountId = ChartOfAccount::query()->where('account_code', $code)->value('id');
            $this->assertSame(
                $accountId,
                AccountMapping::query()->where('mapping_key', $key)->value('account_id'),
                "Mapping [{$key}] harus menunjuk akun [{$code}]."
            );
        }

        // Key generik tetap terisi sebagai fallback posting (kelas Peralatan).
        $peralatan = ChartOfAccount::query()->where('account_code', '1530')->value('id');
        $this->assertSame($peralatan, AccountMapping::query()->where('mapping_key', 'fixed_assets.cost')->value('account_id'));
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
