<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Models\OpeningBalanceBatch;
use App\Modules\Settings\Services\CompanySettingService;
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

        // Modul Aktiva Tetap kini menyala default (CompanySettingService), jadi
        // `opening_fixed_assets` ikut jadi step wajib dan wizard harus
        // menyatakan "tidak ada aset tetap awal" -- persis yang dilakukan
        // Step5OpeningBalance di frontend.
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
     * Modul Aktiva Tetap menyala secara default (temuan Improvement #2): tidak
     * ada tier yang menggerbanginya, jadi setiap perusahaan memilikinya sejak
     * awal dan blok akun aset tetap dari template COA langsung terpakai.
     */
    public function test_fixed_asset_module_is_enabled_by_default(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        $modules = app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);

        $this->assertTrue((bool) $modules->fixed_asset_enabled);
    }

    /**
     * Temuan Improvement #5. "Lewati, isi nanti" hanya berlaku selama batch saldo
     * awal benar-benar tidak ada. Padahal sekadar membuka halaman Saldo Awal dan
     * menekan "Mulai Input Saldo Awal" sudah membuat batch draft KOSONG -- sejak
     * itu skip diam-diam tidak berlaku lagi dan finalize selalu 422
     * (BATCH_MINIMUM_LINES), yang di UI muncul sebagai "Periksa kembali isian
     * yang ditandai" di halaman Selesai yang tidak punya isian apa pun.
     */
    public function test_skip_discards_an_empty_draft_batch_so_finalization_can_proceed(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $this->seedSetupCoaAndMappings();

        $this->patchJson('/api/setup/current-step', [
            'current_step' => 'final_review',
            'opening_date' => '2026-01-01',
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/opening-balance/batches', ['opening_date' => '2026-01-01'], $ctx['headers'])
            ->assertCreated();

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_balance_preview',
            'confirm_opening_balance_skipped' => true,
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.result.valid', true)
            // Kontrak yang dipakai Step5OpeningBalance untuk menulis ringkasan
            // "Belum diisi" vs "Tersimpan" di halaman Selesai.
            ->assertJsonPath('data.result.metadata.skipped', true);

        $this->assertSame(0, OpeningBalanceBatch::query()->count(), 'Batch draft kosong harus dibuang saat user memilih lewati.');

        $this->getJson('/api/opening-balance/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'not_started');
    }

    /**
     * Kebalikannya: batch yang SUDAH berisi baris tidak boleh ikut dibuang.
     * Menghapusnya diam-diam sama saja membuang isian user hanya karena ia
     * menekan tombol lewati.
     */
    public function test_skip_keeps_a_draft_batch_that_already_has_lines(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');
        app(CompanySettingService::class)->getOrCreateModuleSetting($ctx['company']);
        $this->seedSetupCoaAndMappings();

        $batchId = $this->postJson('/api/opening-balance/batches', ['opening_date' => '2026-01-01'], $ctx['headers'])
            ->assertCreated()
            ->json('data.id');

        $cash = (int) ChartOfAccount::query()->where('account_code', '1999')->value('id');
        $equity = (int) ChartOfAccount::query()->where('account_code', '3999')->value('id');

        $this->putJson("/api/opening-balance/batches/{$batchId}/lines", [
            'lines' => [
                ['account_id' => $cash, 'debit' => 1000000, 'credit' => 0],
                ['account_id' => $equity, 'debit' => 0, 'credit' => 1000000],
            ],
        ], $ctx['headers'])->assertOk();

        $this->postJson('/api/setup/validate-step', [
            'step' => 'opening_balance_preview',
            'confirm_opening_balance_skipped' => true,
        ], $ctx['headers'])->assertOk();

        $this->assertSame(1, OpeningBalanceBatch::query()->count());
        $this->getJson('/api/opening-balance/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.batch.total_debit', '1000000.00');
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
            // Modul Aktiva Tetap menyala default, jadi mapping-nya ikut wajib.
            'fixed_assets.clearing' => ['fixed_assets', $asset],
            'fixed_assets.cost' => ['fixed_assets', $asset],
            'fixed_assets.accumulated_depreciation' => ['fixed_assets', $asset],
            'fixed_assets.depreciation_expense' => ['fixed_assets', $expense],
            'fixed_assets.disposal_gain' => ['fixed_assets', $revenue],
            'fixed_assets.disposal_loss' => ['fixed_assets', $expense],
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
