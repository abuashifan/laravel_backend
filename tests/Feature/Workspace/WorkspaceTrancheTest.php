<?php

namespace Tests\Feature\Workspace;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Fase 5: pencairan bertahap, dana muka dengan penyelesaian, dan penutupan
 * kegiatan yang melepas sisa komitmen. Angka mengikuti skenario pilot Ujian.
 */
class WorkspaceTrancheTest extends JournalTestCase
{
    private const BUDGET = '/api/workspace/budget';

    private const QUEUE = '/api/cash-bank/workspace-disbursements';

    public function test_advance_settlement_partial_tranches_and_closing_release_the_unused_commitment(): void
    {
        $tenant = $this->setUpTenant();
        $this->setUpProductTenant($tenant['company'], 'workspace', 'workspace_pilot');
        $headers = $tenant['headers'];
        $cash = $tenant['accounts']['debit'];
        $transport = $this->account('5101', 'Beban Transportasi', 'expense');
        $supplies = $this->account('5102', 'Beban Perlengkapan', 'expense');
        $advanceAccount = $this->account('1150', 'Uang Muka Kegiatan', 'asset');

        // Pagu Rp20 jt, RAB Ujian Rp10 jt (transport 6 jt, perlengkapan 4 jt).
        [$period, $unit, $document] = $this->approvedActivity($headers, ['TR' => '12000000', 'PL' => '8000000'], ['TR' => '6000000', 'PL' => '4000000']);
        $id = $this->getJson(self::BUDGET."/requests/$document", $headers)->json('data.accounting.id');
        [$tr, $pl] = array_column($this->getJson(self::QUEUE.'/'.$id, $headers)->json('data.source_lines'), 'id');
        $this->assertSame(1_000_000_000, $this->committed($unit));

        // Tahap 1: dana muka Rp7 jt (TR 4 jt, PL 3 jt).
        $advance = $this->tranche($headers, $id, 'advance', $cash, [$tr => '4000000', $pl => '3000000']);
        $base = self::QUEUE."/$id/tranches/$advance";
        $this->postJson("$base/qc/approve", ['advance_account_id' => $cash], $headers)->assertUnprocessable()
            ->assertJsonPath('code', 'WORKSPACE_ADVANCE_ACCOUNT_REQUIRED');
        $payment = $this->postJson("$base/qc/approve", ['advance_account_id' => $advanceAccount], $headers)->assertOk()
            ->assertJsonPath('data.tranches.0.state', 'awaiting_posting')->json('data.tranches.0.cash_payment.id');
        $this->postJson("$base/settle", $this->settlement($cash, []), $headers)->assertConflict()
            ->assertJsonPath('code', 'WORKSPACE_ADVANCE_NOT_SETTLEABLE');
        $this->patchJson("/api/cash-bank/cash-payments/$payment/post", [], $headers)->assertOk();
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.state', 'awaiting_settlement')->assertJsonPath('data.accounting.disbursed_minor', 700_000_000)
            ->assertJsonPath('data.accounting.awaiting_minor', 700_000_000)->assertJsonPath('data.accounting.realized_minor', 0);

        // Tahap 2: biaya langsung Rp1 jt untuk perlengkapan; batas per baris dijaga.
        $this->postJson(self::QUEUE."/$id/tranches", $this->trancheBody('direct', $cash, [$pl => '1000001']), $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_TRANCHE_EXCEEDS_BUDGET');
        $direct = $this->tranche($headers, $id, 'direct', $cash, [$pl => '1000000']);
        $this->postJson(self::BUDGET."/requests/$document/close", [], $headers)->assertConflict()
            ->assertJsonPath('code', 'WORKSPACE_ACTIVITY_NOT_SETTLED');
        $directPayment = $this->postJson(self::QUEUE."/$id/tranches/$direct/qc/approve", ['lines' => [['source_line_id' => $pl, 'account_id' => $supplies]]], $headers)
            ->assertOk()->json('data.tranches.1.cash_payment.id');
        $this->patchJson("/api/cash-bank/cash-payments/$directPayment/post", [], $headers)->assertOk();

        // Tahap 3 dicatat lalu dibatalkan: tidak dihitung.
        $cancelled = $this->tranche($headers, $id, 'direct', $cash, [$tr => '2000000']);
        $this->postJson(self::QUEUE."/$id/tranches/$cancelled/cancel", ['note' => 'Salah catat'], $headers)->assertOk()
            ->assertJsonPath('data.tranches.2.state', 'cancelled')->assertJsonPath('data.source_lines.0.remaining_minor', 200_000_000);

        // Penyelesaian dana muka: TR 3,5 jt, PL 2 jt, kembali 1,5 jt ke kas.
        $verified = [['source_line_id' => $tr, 'amount' => '3500000', 'account_id' => $transport], ['source_line_id' => $pl, 'amount' => '2000000', 'account_id' => $supplies]];
        $this->postJson("$base/settle", $this->settlement(null, $verified), $headers)->assertUnprocessable()->assertJsonPath('code', 'CASH_BANK_ACCOUNT_REQUIRED');
        $this->postJson("$base/settle", $this->settlement($cash, [['source_line_id' => $tr, 'amount' => '4000001', 'account_id' => $transport]]), $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_SETTLEMENT_EXCEEDS_ADVANCE');
        $settled = $this->postJson("$base/settle", $this->settlement($cash, $verified), $headers)->assertOk()
            ->assertJsonPath('data.tranches.0.state', 'complete')->assertJsonPath('data.tranches.0.refund_minor', 150_000_000)->json('data');
        $journal = JournalEntry::query()->findOrFail($settled['tranches'][0]['settlement_journal']['id']);
        $this->assertSame(['debit' => 700.0e4, 'credit' => 700.0e4], ['debit' => (float) $journal->lines()->sum('debit'), 'credit' => (float) $journal->lines()->sum('credit')]);
        $this->assertSame(150.0e4, (float) $journal->lines()->where('account_id', $cash)->sum('debit'));
        $this->assertSame(700.0e4, (float) $journal->lines()->where('account_id', $advanceAccount)->sum('credit'));

        // Batalkan lalu ulangi penyelesaian: jurnal pertama di-void, angka kembali sama.
        $this->postJson("$base/settlement/cancel", ['note' => 'Bukti kurang'], $headers)->assertOk()->assertJsonPath('data.tranches.0.state', 'awaiting_settlement');
        $this->assertSame('void', $journal->refresh()->status);
        $this->postJson("$base/settle", $this->settlement($cash, $verified), $headers)->assertOk();

        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.state', 'complete')->assertJsonPath('data.accounting.disbursed_minor', 800_000_000)
            ->assertJsonPath('data.accounting.realized_minor', 650_000_000)->assertJsonPath('data.accounting.refund_minor', 150_000_000)
            ->assertJsonPath('data.accounting.awaiting_minor', 0);
        $detail = $this->getJson('/api/workspace/reports/activities/'.$document, $headers)->assertOk()->json('data');
        $this->assertSame([350_000_000, 300_000_000], array_column($detail['rows'], 'realized_minor'));
        $this->getJson("/api/workspace/reports/reconciliation?period_id=$period", $headers)->assertOk()
            ->assertJsonPath('data.linked_in_range_minor', 650_000_000)->assertJsonPath('data.unlinked_minor', 0)->assertJsonPath('data.mismatches', []);

        // Selesaikan kegiatan: sisa komitmen Rp3,5 jt (TR 2,5 jt, PL 1 jt) kembali ke pagu.
        $this->postJson(self::BUDGET."/requests/$document/close", ['note' => 'Ujian selesai'], $headers)->assertOk()
            ->assertJsonPath('data.released_minor', 350_000_000)->assertJsonPath('data.accounting.state', 'closed');
        $this->postJson(self::BUDGET."/requests/$document/close", [], $headers)->assertOk()->assertJsonPath('data.released_minor', 350_000_000);
        $this->assertSame(650_000_000, $this->committed($unit));
        $this->assertSame([250_000_000, 100_000_000], DB::connection('workspace')->table('workspace_budget_releases')->orderBy('item_id')->pluck('released_minor')->map(fn ($v) => (int) $v)->all());
        $this->getJson("/api/workspace/reports/units?period_id=$period", $headers)->assertOk()
            ->assertJsonPath('data.total.released_minor', 350_000_000)->assertJsonPath('data.total.remaining_minor', 1_350_000_000)
            ->assertJsonPath('data.total.realized_minor', 650_000_000);
        $this->postJson(self::QUEUE."/$id/tranches", $this->trancheBody('direct', $cash, [$tr => '100000']), $headers)
            ->assertConflict()->assertJsonPath('code', 'WORKSPACE_ACTIVITY_CLOSED');
        $this->postJson("$base/settlement/cancel", ['note' => 'Terlambat'], $headers)->assertConflict();
    }

    public function test_phase_three_disbursement_rows_become_the_first_tranche(): void
    {
        $tenant = $this->setUpTenant();
        $this->setUpProductTenant($tenant['company'], 'workspace', 'workspace_pilot');
        $headers = $tenant['headers'];
        [, , $document] = $this->approvedActivity($headers, ['TR' => '100'], ['TR' => '65.40']);
        $id = $this->getJson(self::BUDGET."/requests/$document", $headers)->json('data.accounting.id');
        DB::connection('tenant')->table('workspace_disbursement_tranches')->delete();
        DB::connection('tenant')->table('workspace_disbursements')->where('id', $id)->update([
            'disbursed_on' => '2026-01-11', 'disbursed_by' => 1, 'disbursed_at' => now(), 'cash_bank_account_id' => $tenant['accounts']['debit'],
            'payment_reference' => 'TRF-LAMA', 'qc_status' => 'rejected', 'qc_note' => 'Bukti kurang',
        ]);
        $migration = require database_path('migrations/tenant/2026_11_26_000001_create_workspace_disbursement_tranches.php');
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('tenant');
        try {
            $migration->down();
            $migration->up();
        } finally {
            DB::setDefaultConnection($default);
        }
        $this->getJson(self::QUEUE.'/'.$id, $headers)->assertOk()
            ->assertJsonPath('data.state', 'returned')->assertJsonPath('data.tranches.0.sequence', 1)
            ->assertJsonPath('data.tranches.0.payment_reference', 'TRF-LAMA')->assertJsonPath('data.tranches.0.amount_minor', 6540)
            ->assertJsonPath('data.disbursed_minor', 6540);
    }

    private function account(string $code, string $name, string $type): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code, 'account_name' => $name, 'account_type' => $type,
            'normal_balance' => 'debit', 'is_active' => true, 'is_cash_bank' => false,
        ])->id;
    }

    private function committed(int $unit): int
    {
        return (int) DB::connection('workspace')->table('workspace_budget_items as i')->join('workspace_budget_plans as p', 'p.id', '=', 'i.plan_id')
            ->where('p.unit_id', $unit)->sum('i.committed_minor');
    }

    private function trancheBody(string $kind, int $cash, array $amounts): array
    {
        return ['kind' => $kind, 'disbursed_on' => '2026-01-11', 'cash_bank_account_id' => $cash, 'payment_reference' => 'TRF-'.$kind,
            'lines' => collect($amounts)->map(fn ($amount, $line) => ['source_line_id' => $line, 'amount' => $amount])->values()->all()];
    }

    private function tranche(array $headers, int $id, string $kind, int $cash, array $amounts): int
    {
        return collect($this->postJson(self::QUEUE."/$id/tranches", $this->trancheBody($kind, $cash, $amounts), $headers)->assertCreated()
            ->json('data.tranches'))->last()['id'];
    }

    private function settlement(?int $refund, array $lines): array
    {
        return ['settled_on' => '2026-01-20', 'refund_cash_bank_account_id' => $refund, 'lines' => $lines];
    }

    /** @return array{0:int,1:int,2:int} periode, unit, dokumen kegiatan disetujui */
    private function approvedActivity(array $headers, array $annual, array $activity): array
    {
        $period = $this->postJson(self::BUDGET.'/periods', ['name' => 'Pagu 2026', 'period_from' => '2026-01-01'], $headers)->assertCreated()->json('data.id');
        $unit = $this->postJson('/api/workspace/units', ['code' => 'A', 'name' => 'Sekolah A', 'is_active' => true], $headers)->assertCreated()->json('data.id');
        $plan = $this->postJson(self::BUDGET.'/requests', [
            'kind' => 'annual', 'unit_id' => $unit, 'period_id' => $period, 'title' => 'RAB Tahunan',
            'lines' => collect($annual)->map(fn ($amount, $code) => ['code' => $code, 'description' => $code, 'amount' => $amount])->values()->all(),
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson(self::BUDGET."/requests/$plan/submit", [], $headers)->assertOk();
        $this->postJson(self::BUDGET."/requests/$plan/approve", [], $headers)->assertOk();
        $items = collect($this->getJson(self::BUDGET."/items?kind=activity&period_id=$period&unit_id=$unit", $headers)->json('data'))->keyBy('code');
        $document = $this->postJson(self::BUDGET.'/requests', [
            'kind' => 'activity', 'unit_id' => $unit, 'period_id' => $period, 'title' => 'Ujian', 'activity_date' => '2026-01-10',
            'lines' => collect($activity)->map(fn ($amount, $code) => ['item_id' => $items[$code]['id'], 'description' => 'Ujian '.$code, 'amount' => $amount])->values()->all(),
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson(self::BUDGET."/requests/$document/submit", [], $headers)->assertOk();
        $this->postJson(self::BUDGET."/requests/$document/approve", [], $headers)->assertOk()->assertJsonPath('data.accounting.sync_status', 'delivered');

        return [$period, $unit, $document];
    }
}
