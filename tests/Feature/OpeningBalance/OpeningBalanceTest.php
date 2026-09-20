<?php

namespace Tests\Feature\OpeningBalance;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\OpeningBalance\Services\OpeningBalanceService;
use App\Shared\Models\CompanySetupState;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Tenant\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Papan pemantau saldo awal — Fase 8.
 *
 * Tidak ada lagi batch, validate, post, lock, atau reopen. Yang tersisa: satu
 * tanggal, satu akun perantara, daftar jurnal yang bisa dibatalkan sendiri-
 * sendiri, dan satu tombol penutup ke ekuitas.
 */
class OpeningBalanceTest extends JournalTestCase
{
    use RefreshDatabase;

    public function test_status_starts_empty_and_reports_the_opening_date(): void
    {
        $ctx = $this->setUpOpeningTenant();

        $this->getJson('/api/opening-balance/status', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.opening_date', '2026-01-01')
            ->assertJsonPath('data.journal_count', 0)
            ->assertJsonPath('data.is_complete', false)
            ->assertJsonPath('data.clearing_account.account_code', '3900');
    }

    public function test_opening_date_can_be_changed_until_the_first_journal_exists(): void
    {
        $ctx = $this->setUpOpeningTenant();

        $this->putJson('/api/opening-balance/opening-date', ['opening_date' => '2025-07-01'], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.opening_date', '2025-07-01');

        $this->postOpeningJournal(['1101' => ['debit' => 5000000]]);

        // Setelah ada jurnal, tanggalnya terkunci: mengubahnya diam-diam akan
        // membuat jurnal yang sudah terbit bertanggal lain dari yang berikutnya.
        $this->putJson('/api/opening-balance/opening-date', ['opening_date' => '2025-01-01'], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_BALANCE_DATE_LOCKED');
    }

    public function test_clearing_balance_is_the_unrecognised_opening_equity(): void
    {
        $ctx = $this->setUpOpeningTenant();

        // Aset 10jt, liabilitas 4jt → ekuitas pembuka 6jt.
        $this->postOpeningJournal([
            '1101' => ['debit' => 10000000],
            '2100' => ['credit' => 4000000],
        ]);

        $status = $this->getJson('/api/opening-balance/status', $ctx['headers'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(-6000000, (float) $status['clearing_balance'], 0.001);
        $this->assertFalse($status['is_complete']);
    }

    public function test_closing_splits_the_clearing_balance_across_equity_accounts(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $this->postOpeningJournal([
            '1101' => ['debit' => 10000000],
            '2100' => ['credit' => 4000000],
        ]);

        $this->postJson('/api/opening-balance/close-clearing', [
            'targets' => [
                ['account_id' => $this->accountId('3100'), 'amount' => 4000000],
                ['account_id' => $this->accountId('3200'), 'amount' => 2000000],
            ],
        ], $ctx['headers'])->assertOk();

        $this->assertEqualsWithDelta(4000000, $this->credited('3100'), 0.001);
        $this->assertEqualsWithDelta(2000000, $this->credited('3200'), 0.001);

        $status = $this->getJson('/api/opening-balance/status', $ctx['headers'])->assertOk()->json('data');
        $this->assertEqualsWithDelta(0, (float) $status['clearing_balance'], 0.001);
        $this->assertTrue($status['is_complete']);
    }

    public function test_split_that_does_not_add_up_is_rejected(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $this->postOpeningJournal(['1101' => ['debit' => 10000000]]);

        $this->postJson('/api/opening-balance/close-clearing', [
            'targets' => [['account_id' => $this->accountId('3100'), 'amount' => 3000000]],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_BALANCE_CLOSE_SPLIT_MISMATCH');
    }

    public function test_non_equity_close_target_is_rejected(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $this->postOpeningJournal(['1101' => ['debit' => 10000000]]);

        $this->postJson('/api/opening-balance/close-clearing', [
            'targets' => [['account_id' => $this->accountId('1101'), 'amount' => 10000000]],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'OPENING_BALANCE_CLOSE_ACCOUNT_INVALID');
    }

    /**
     * Tiap jurnal berdiri sendiri — membatalkan yang satu tidak menyentuh yang
     * lain, dan tidak ada dokumen yang perlu di-reopen lebih dulu.
     */
    public function test_a_single_opening_journal_can_be_voided_on_its_own(): void
    {
        $ctx = $this->setUpOpeningTenant();
        $first = $this->postOpeningJournal(['1101' => ['debit' => 10000000]]);
        $this->postOpeningJournal(['1102' => ['debit' => 5000000]]);

        $this->deleteJson('/api/opening-balance/journals/'.$first->id, ['reason' => 'Angka kas salah'], $ctx['headers'])
            ->assertOk();

        $this->assertSame('void', $first->refresh()->status);

        $status = $this->getJson('/api/opening-balance/status', $ctx['headers'])->assertOk()->json('data');
        $this->assertSame(1, $status['journal_count']);
        $this->assertEqualsWithDelta(-5000000, (float) $status['clearing_balance'], 0.001);
    }

    public function test_voiding_a_journal_outside_opening_balance_is_refused(): void
    {
        $ctx = $this->setUpOpeningTenant();

        $this->deleteJson('/api/opening-balance/journals/99999', ['reason' => 'coba-coba'], $ctx['headers'])
            ->assertStatus(404)
            ->assertJsonPath('code', 'OPENING_BALANCE_JOURNAL_NOT_FOUND');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function setUpOpeningTenant(): array
    {
        $ctx = $this->setUpTenant(role: 'owner');

        CompanySetupState::query()->updateOrCreate(
            ['company_id' => $ctx['company']->id],
            ['opening_date' => '2026-01-01'],
        );

        foreach ([
            ['1101', 'Kas', 'asset', 'debit'],
            ['1102', 'Bank', 'asset', 'debit'],
            ['2100', 'Utang Usaha', 'liability', 'credit'],
            ['3100', 'Modal Pemilik', 'equity', 'credit'],
            ['3200', 'Laba Ditahan', 'equity', 'credit'],
            ['3900', 'Saldo Awal (Perantara)', 'equity', 'credit'],
        ] as [$code, $name, $type, $normal]) {
            ChartOfAccount::query()->firstOrCreate(
                ['account_code' => $code],
                ['account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_active' => true],
            );
        }

        foreach ([
            'opening_balance.equity' => '3100',
            'opening_balance.clearing' => '3900',
        ] as $key => $code) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                ['module' => 'opening_balance', 'account_id' => $this->accountId($code), 'is_required' => true, 'is_active' => true],
            );
        }

        // Beberapa test memanggil service langsung, di luar siklus request —
        // di situ `company.access` tidak pernah berjalan, jadi konteks tenantnya
        // dipasang di sini.
        app(TenantContext::class)->set(
            $ctx['company'],
            CompanyUser::query()->where('company_id', $ctx['company']->id)->firstOrFail(),
            TenantDatabase::query()->where('company_id', $ctx['company']->id)->firstOrFail(),
        );

        return $ctx;
    }

    /**
     * @param  array<string, array{debit?: float, credit?: float}>  $lines
     */
    private function postOpeningJournal(array $lines): JournalEntry
    {
        $payload = [];
        foreach ($lines as $code => $amounts) {
            $payload[] = [
                'account_id' => $this->accountId($code),
                'debit' => (float) ($amounts['debit'] ?? 0),
                'credit' => (float) ($amounts['credit'] ?? 0),
                'description' => 'Saldo awal '.$code,
            ];
        }

        return app(OpeningBalanceService::class)->postOpeningJournal($payload);
    }

    private function credited(string $code): float
    {
        return (float) \DB::connection('tenant')->table('journal_entry_lines')
            ->where('account_id', $this->accountId($code))
            ->sum('credit');
    }

    private function accountId(string $code): int
    {
        return (int) ChartOfAccount::query()->where('account_code', $code)->value('id');
    }
}
