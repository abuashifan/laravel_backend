<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Settings\Services\CompanySettingService;
use App\Shared\Models\CompanyModuleSetting;
use App\Shared\Models\CompanySetupState;
use Tests\Feature\Journal\JournalTestCase;

class SetupWizardTest extends JournalTestCase
{
    public function test_setup_routes_require_setup_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'warehouse');

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_status_creates_default_state_and_current_step_can_store_opening_date(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.state.status', 'not_started')
            ->assertJsonPath('data.state.current_step', 'company_profile');

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'accounting_settings',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.state.status', 'in_progress')
            ->assertJsonPath('data.state.current_step', 'accounting_settings')
            ->assertJsonPath('data.state.opening_date', '2026-01-01');
    }

    public function test_gate_offers_initial_setup_only_while_books_are_empty(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.gate.is_finalized', false)
            ->assertJsonPath('data.gate.has_operational_data', false)
            ->assertJsonPath('data.gate.initial_setup_available', true);

        // Satu transaksi operasional cukup untuk menutup alur setup awal,
        // walaupun setup state belum pernah difinalisasi.
        JournalEntry::query()->create([
            'journal_number' => 'JV-GATE-001',
            'journal_date' => '2026-02-01',
            'description' => 'Transaksi operasional',
            'status' => 'posted',
            'total_debit' => 1000,
            'total_credit' => 1000,
        ]);

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.gate.has_operational_data', true)
            ->assertJsonPath('data.gate.initial_setup_available', false);
    }

    public function test_gate_closes_initial_setup_once_finalized(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        CompanySetupState::query()->updateOrCreate(
            ['company_id' => $ctx['company']->id],
            ['status' => 'finalized', 'current_step' => 'finalized', 'finalized_at' => now()],
        );

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.gate.is_finalized', true)
            ->assertJsonPath('data.gate.initial_setup_available', false);
    }

    /**
     * Fase 8: langkah saldo awal cuma menuntut satu hal — tanggalnya.
     *
     * Sampai Fase 7 ia menuntut sebuah batch yang seimbang dan tervalidasi,
     * yang berarti user tidak bisa menyelesaikan setup sebelum seluruh neraca
     * pembukanya beres. Mengisi saldo awal adalah pekerjaan yang boleh dicicil,
     * jadi wizard tidak lagi menahannya.
     */
    public function test_opening_balance_step_only_requires_the_opening_date(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $this->seedSetupCoaAndMappings();

        $this->postJson('/api/setup/validate-step', ['step' => 'opening_balance'], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.result.valid', false)
            ->assertJsonPath('data.result.errors.0.code', 'OPENING_DATE_REQUIRED');

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_balance',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.result.valid', true);
    }

    /**
     * Belum ada satu pun jurnal pembuka bukan penghalang — ia peringatan.
     * Tidak ada lagi flag "lewati" yang harus dikirim frontend.
     */
    public function test_finalization_succeeds_without_any_opening_balance_journal(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $this->seedSetupCoaAndMappings();

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'final_review',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/setup/validate-all', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.results.opening_balance.warnings.0', 'Belum ada jurnal saldo awal. Perusahaan ini akan mulai dari nol.');

        $this->postJson('/api/setup/finalize', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.finalized', true)
            ->assertJsonPath('data.state.status', 'finalized');
    }

    /**
     * Regresi terbalik dari Fase 7: modul Aktiva Tetap yang aktif tanpa aset
     * terdaftar dulu memblokir finalisasi sampai user mencentang "tidak punya
     * aset tetap awal". Sejak Fase 8 aset boleh didaftarkan kapan saja — bahkan
     * setelah setup selesai — jadi tidak ada yang perlu dikonfirmasi.
     */
    public function test_enabled_fixed_asset_module_no_longer_blocks_finalization(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        CompanyModuleSetting::query()->updateOrCreate(
            ['company_id' => $ctx['company']->id],
            ['fixed_asset_enabled' => true],
        );
        $this->seedSetupCoaAndMappings();
        $this->seedFixedAssetMappings();

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'final_review',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/setup/validate-all', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $this->postJson('/api/setup/finalize', [], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.finalized', true);
    }

    /**
     * Status wizard membawa papan pemantau saldo awal, bukan gerbang urutan:
     * tidak ada lagi urutan yang perlu dijaga.
     */
    public function test_status_exposes_the_opening_balance_board(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $this->seedSetupCoaAndMappings();

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.opening_balance.journal_count', 0)
            ->assertJsonPath('data.opening_balance.is_complete', false)
            ->assertJsonPath('data.opening_balance.ready', true)
            ->assertJsonPath('data.opening_balance.clearing_account.account_code', '3990');
    }

    public function test_finalized_setup_cannot_be_downgraded_by_stale_current_step_request(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        CompanySetupState::query()->create([
            'company_id' => $ctx['company']->id,
            'status' => 'finalized',
            'current_step' => 'finalized',
            'completed_steps' => ['company_profile', 'finalized'],
            'validation_errors' => [],
            'finalized_at' => now(),
            'finalized_by' => $ctx['user']->id,
        ]);

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'company_profile',
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'SETUP_ALREADY_FINALIZED');
    }

    private function seedSetupCoaAndMappings(): void
    {
        $asset = $this->account('1999', 'Setup Asset', 'asset', 'debit', true);
        $liability = $this->account('2999', 'Setup Payable', 'liability', 'credit');
        $equity = $this->account('3999', 'Setup Equity', 'equity', 'credit');
        $revenue = $this->account('4999', 'Setup Revenue', 'revenue', 'credit');
        $expense = $this->account('6999', 'Setup Expense', 'expense', 'debit');

        foreach ([
            'sales.accounts_receivable' => ['sales', $asset],
            'sales.revenue' => ['sales', $revenue],
            'sales.customer_deposit' => ['sales', $liability],
            'purchase.accounts_payable' => ['purchase', $liability],
            'purchase.expense' => ['purchase', $expense],
            'purchase.vendor_deposit' => ['purchase', $asset],
            'cash_bank.default_cash' => ['cash_bank', $asset],
            'cash_bank.default_bank' => ['cash_bank', $asset],
            'opening_balance.equity' => ['opening_balance', $equity],
            'opening_balance.clearing' => ['opening_balance', $this->account('3990', 'Setup Clearing', 'equity', 'credit')],
        ] as $key => [$module, $accountId]) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                [
                    'module' => $module,
                    'account_id' => $accountId,
                    'is_required' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * Mengaktifkan modul Aktiva Tetap membuat 4 mapping key `fixed_assets.*`
     * jadi wajib (lihat AccountMappingHealthTest / config/account_mappings.php);
     * kunci penyusutan per kelas ikut diisi di sini karena jadi fallback posting
     * setelah kunci generiknya dihapus.
     * Di wizard sungguhan ini terisi otomatis lewat
     * AccountMappingStorageService::syncDefaultMappingsFromConfig() setelah
     * Step3 menerapkan template COA -- di sini diisi manual karena test tidak
     * lewat endpoint template.
     */
    private function seedFixedAssetMappings(): void
    {
        $asset = $this->account('1590', 'Fixed Asset Clearing', 'asset', 'debit');
        $cost = $this->account('1530', 'Peralatan', 'asset', 'debit');
        $accumulated = $this->account('1531', 'Akumulasi Penyusutan Peralatan', 'asset', 'debit');
        $expense = $this->account('6172', 'Beban Penyusutan Peralatan', 'expense', 'debit');
        $gain = $this->account('7200', 'Laba Pelepasan Aset Tetap', 'revenue', 'credit');
        $loss = $this->account('8200', 'Rugi Pelepasan Aset Tetap', 'expense', 'debit');

        foreach ([
            'fixed_assets.clearing' => $asset,
            'fixed_assets.cost' => $cost,
            'fixed_assets.equipment_accumulated_depreciation' => $accumulated,
            'fixed_assets.equipment_depreciation_expense' => $expense,
            'fixed_assets.disposal_gain' => $gain,
            'fixed_assets.disposal_loss' => $loss,
        ] as $key => $accountId) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                [
                    'module' => 'fixed_assets',
                    'account_id' => $accountId,
                    'is_required' => true,
                    'is_active' => true,
                ]
            );
        }
    }

    private function account(string $code, string $name, string $type, string $normalBalance, bool $cashBank = false): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normalBalance,
            'is_cash_bank' => $cashBank,
            'is_active' => true,
            'is_system_default' => false,
        ])->id;
    }
}
