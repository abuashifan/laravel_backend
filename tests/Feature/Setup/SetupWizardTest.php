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

    public function test_validate_all_blocks_when_opening_balance_batch_is_missing(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $this->seedSetupCoaAndMappings();

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'final_review',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertOk();

        $response = $this->postJson('/api/setup/validate-all', [], $ctx['headers'])
            ->assertOk();

        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.results.opening_balance_preview.errors.0.code', 'OPENING_BALANCE_BATCH_REQUIRED');
        $response->assertJsonPath('data.state.status', 'in_progress');
    }

    /**
     * Wizard menawarkan "Lewati, isi nanti" untuk saldo awal (perusahaan baru tanpa
     * saldo historis wajar tidak punya apa pun untuk diinput). Tanpa flag skip ini
     * finalize selalu gagal 422 walau semua step lain valid -- lihat
     * SetupWizardService::openingBalanceSkipped().
     */
    public function test_opening_balance_can_be_explicitly_skipped_for_finalization(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $this->seedSetupCoaAndMappings();

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'final_review',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_balance_preview',
            'confirm_opening_balance_skipped' => true,
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.result.valid', true);

        $response = $this->postJson('/api/setup/finalize', [], $ctx['headers'])->assertOk();
        $response->assertJsonPath('data.finalized', true);
        $response->assertJsonPath('data.state.status', 'finalized');
    }

    /**
     * Reproduksi bug: perusahaan yang mengaktifkan modul Aktiva Tetap di Step 2
     * wizard tapi belum punya aset tetap tidak pernah bisa finalize -- frontend
     * lama tidak punya UI untuk step `opening_fixed_assets` sama sekali, jadi
     * `confirm_no_opening_fixed_assets` tidak pernah terkirim dan user macet di
     * "Selesai" dengan toast generik "Periksa kembali isian yang ditandai" tanpa
     * field apa pun yang ditandai (lihat Step5OpeningBalance.tsx).
     */
    public function test_finalize_blocks_when_fixed_asset_module_enabled_without_opening_fixed_assets_confirmation(): void
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

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_balance_preview',
            'confirm_opening_balance_skipped' => true,
        ], $ctx['headers'])->assertOk();

        $response = $this->postJson('/api/setup/validate-all', [], $ctx['headers'])->assertOk();

        $response->assertJsonPath('data.valid', false);
        $response->assertJsonPath('data.results.opening_fixed_assets.errors.0.code', 'OPENING_FIXED_ASSETS_NOT_CONFIRMED');

        $this->postJson('/api/setup/finalize', [], $ctx['headers'])->assertStatus(422);
    }

    /**
     * Alur wizard yang benar (Step5OpeningBalance): saat modul Aktiva Tetap
     * aktif, wizard mengirim `confirm_no_opening_fixed_assets` bersamaan
     * dengan `confirm_opening_balance_skipped` sebelum finalize.
     */
    public function test_opening_fixed_assets_can_be_explicitly_confirmed_as_none_for_finalization(): void
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

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_balance_preview',
            'confirm_opening_balance_skipped' => true,
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_fixed_assets',
            'confirm_no_opening_fixed_assets' => true,
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.result.valid', true);

        $response = $this->postJson('/api/setup/finalize', [], $ctx['headers'])->assertOk();
        $response->assertJsonPath('data.finalized', true);
        $response->assertJsonPath('data.state.status', 'finalized');
    }

    /**
     * Gerbang urutan "aset tetap awal dulu" yang dibaca Step 6 wizard untuk
     * mengunci tombol impor saldo awal. Aturan yang sama ditegakkan di jalur
     * impor oleh `OpeningBalanceImportCommitter`; field ini ada supaya UI tidak
     * menyimpulkan sendiri dan berakhir beda pendapat dengan backend.
     */
    public function test_status_exposes_opening_fixed_assets_ordering_gate(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        // Modul mati -> gerbang terbuka; layar saldo awal tidak mengunci apa pun.
        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.opening_fixed_assets.module_enabled', false)
            ->assertJsonPath('data.opening_fixed_assets.settled', true);

        CompanyModuleSetting::query()->updateOrCreate(
            ['company_id' => $ctx['company']->id],
            ['fixed_asset_enabled' => true],
        );

        // Modul hidup, register kosong, belum dikonfirmasi -> terkunci.
        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.opening_fixed_assets.module_enabled', true)
            ->assertJsonPath('data.opening_fixed_assets.imported_count', 0)
            ->assertJsonPath('data.opening_fixed_assets.confirmed_none', false)
            ->assertJsonPath('data.opening_fixed_assets.settled', false);

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_fixed_assets',
            'confirm_no_opening_fixed_assets' => true,
        ], $ctx['headers'])->assertOk();

        $this->getJson('/api/setup/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.opening_fixed_assets.confirmed_none', true)
            ->assertJsonPath('data.opening_fixed_assets.settled', true);
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
