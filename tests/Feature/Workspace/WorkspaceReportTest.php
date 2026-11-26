<?php

namespace Tests\Feature\Workspace;

use App\Modules\MasterData\Models\ChartOfAccount;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Fase 4: angka pilot sintetis harus cocok di tiga tingkat laporan dan di
 * rekonsiliasi jurnal Accounting (dokumen 0 skenario pilot, Q7).
 */
class WorkspaceReportTest extends JournalTestCase
{
    private const BUDGET = '/api/workspace/budget';

    private const REPORTS = '/api/workspace/reports';

    private const QUEUE = '/api/cash-bank/workspace-disbursements';

    public function test_three_report_levels_and_journal_reconciliation_agree(): void
    {
        $tenant = $this->setUpTenant();
        $this->setUpProductTenant($tenant['company'], 'workspace', 'workspace_pilot');
        $headers = $tenant['headers'];
        $period = $this->postJson(self::BUDGET.'/periods', ['name' => 'Pagu 2026', 'period_from' => '2026-01-01'], $headers)->assertCreated()->json('data.id');
        $expense = ChartOfAccount::query()->create([
            'account_code' => '5001', 'account_name' => 'Beban kegiatan', 'account_type' => 'expense',
            'normal_balance' => 'debit', 'is_active' => true, 'is_cash_bank' => false,
        ]);

        $a = $this->unitWithBudget($headers, $period, 'A', 'Sekolah A', ['TR' => '12000000', 'PL' => '8000000']);
        $b = $this->unitWithBudget($headers, $period, 'B', 'Sekolah B', ['TR' => '6000000', 'PL' => '4000000']);
        $ujian = $this->approvedActivity($headers, $period, $a, 'Ujian', ['TR' => '6000000', 'PL' => '4000000']);
        $lomba = $this->approvedActivity($headers, $period, $a, 'Lomba', ['TR' => '1500000', 'PL' => '500000']);
        $this->approvedActivity($headers, $period, $b, 'Kunjungan', ['TR' => '3000000', 'PL' => '1000000']);

        // Ujian: dicairkan, QC disetujui, diposting. Lomba: dicairkan, menunggu QC. Kunjungan: belum dicairkan.
        $journal = $this->disburse($headers, $tenant, $ujian, $expense->id, post: true);
        $this->disburse($headers, $tenant, $lomba, $expense->id, post: false);
        // Biaya Accounting tanpa rujukan kegiatan.
        $manual = $this->postJson('/api/cash-bank/cash-payments', [
            'payment_date' => '2026-02-01', 'cash_bank_account_id' => $tenant['accounts']['debit'], 'amount' => 250000,
            'lines' => [['account_id' => $expense->id, 'amount' => 250000, 'description' => 'Listrik kantor']],
        ], $headers)->assertCreated()->json('data.id');
        $this->patchJson("/api/cash-bank/cash-payments/$manual/post", [], $headers)->assertOk();

        $units = $this->getJson(self::REPORTS."/units?period_id=$period", $headers)->assertOk()
            ->assertJsonPath('data.accounting_available', true)->assertJsonPath('data.scope', 'organization')->json('data');
        $this->assertSame([
            'limit_minor' => 3_000_000_000, 'committed_minor' => 1_600_000_000, 'approved_minor' => 1_600_000_000,
            'disbursed_minor' => 1_200_000_000, 'awaiting_minor' => 200_000_000, 'realized_minor' => 1_000_000_000,
            'refund_minor' => 0, 'released_minor' => 0, 'remaining_minor' => 1_400_000_000,
        ], $units['total']);
        $rowA = collect($units['rows'])->firstWhere('unit_id', $a);
        $this->assertSame([2_000_000_000, 1_200_000_000, 200_000_000, 1_000_000_000, 800_000_000],
            [$rowA['limit_minor'], $rowA['approved_minor'], $rowA['awaiting_minor'], $rowA['realized_minor'], $rowA['remaining_minor']]);
        $this->assertSame(0, collect($units['rows'])->firstWhere('unit_id', $b)['realized_minor']);

        $unit = $this->getJson(self::REPORTS."/units/$a?period_id=$period", $headers)->assertOk()->json('data');
        $this->assertSame(['Ujian', 'Lomba'], array_column($unit['rows'], 'title'));
        foreach (['approved_minor', 'disbursed_minor', 'awaiting_minor', 'realized_minor'] as $key) {
            $this->assertSame($rowA[$key], $unit['total'][$key], $key);
        }
        $this->assertSame(['complete', 'awaiting_qc'], array_column($unit['rows'], 'state'));

        $detail = $this->getJson(self::REPORTS."/activities/$ujian", $headers)->assertOk()
            ->assertJsonPath('data.accounting.tranches.0.journal.id', $journal)->assertJsonPath('data.accounting.state', 'complete')->json('data');
        $ujianRow = collect($unit['rows'])->firstWhere('document_id', $ujian);
        $this->assertSame($ujianRow['realized_minor'], $detail['total']['realized_minor']);
        $this->assertSame([600_000_000, 400_000_000], array_column($detail['rows'], 'realized_minor'));
        $this->assertSame(['TR', 'PL'], array_column($detail['rows'], 'item_code'));

        $this->getJson(self::REPORTS."/reconciliation?period_id=$period", $headers)->assertOk()
            ->assertJsonPath('data.workspace_realized_minor', 1_000_000_000)
            ->assertJsonPath('data.workspace_awaiting_minor', 200_000_000)
            ->assertJsonPath('data.undelivered_count', 0)
            ->assertJsonPath('data.gl_expense_minor', 1_025_000_000)
            ->assertJsonPath('data.linked_in_range_minor', 1_000_000_000)
            ->assertJsonPath('data.unlinked_minor', 25_000_000)
            ->assertJsonPath('data.unlinked_count', 1)
            ->assertJsonPath('data.unlinked.0.description', fn ($value) => is_string($value))
            ->assertJsonPath('data.mismatches', []);

        // Void kas keluar Ujian: realisasi kembali nol di semua tingkat.
        $paymentId = $this->getJson(self::REPORTS."/activities/$ujian", $headers)->json('data.accounting.tranches.0.cash_payment.id');
        $this->patchJson("/api/cash-bank/cash-payments/$paymentId/void", ['reason' => 'Koreksi pencatatan'], $headers)->assertOk();
        $this->getJson(self::REPORTS."/units?period_id=$period", $headers)->assertOk()
            ->assertJsonPath('data.total.realized_minor', 0)->assertJsonPath('data.total.awaiting_minor', 1_200_000_000);
        $this->getJson(self::REPORTS."/reconciliation?period_id=$period", $headers)->assertOk()
            ->assertJsonPath('data.gl_expense_minor', 25_000_000)->assertJsonPath('data.linked_in_range_minor', 0);

        // Staf unit B hanya melihat unit B dan tidak bisa membuka rekonsiliasi atau kegiatan unit A.
        $staff = User::factory()->create(['status' => 'active']);
        $member = CompanyUser::query()->create([
            'company_id' => $tenant['company']->id, 'user_id' => $staff->id, 'role' => 'viewer', 'status' => 'active', 'full_access' => false,
        ]);
        $this->assignTestPermissions($member, ['workspace.budget.view', 'workspace.units.view']);
        $this->putJson("/api/workspace/units/$b/members/{$member->id}", [], $headers)->assertOk();
        Sanctum::actingAs($staff, ['client']);
        $this->getJson(self::REPORTS."/units?period_id=$period", $headers)->assertOk()
            ->assertJsonPath('data.scope', 'assigned_units')->assertJsonCount(1, 'data.rows')->assertJsonPath('data.rows.0.unit_id', $b)
            ->assertJsonPath('data.total.limit_minor', 1_000_000_000);
        $this->getJson(self::REPORTS."/units/$a?period_id=$period", $headers)->assertNotFound();
        $this->getJson(self::REPORTS."/activities/$ujian", $headers)->assertNotFound();
        $this->getJson(self::REPORTS."/reconciliation?period_id=$period", $headers)->assertForbidden();
    }

    public function test_company_without_workspace_cannot_open_reports_and_keeps_accounting_budget(): void
    {
        $tenant = $this->setUpTenant();
        $this->getJson(self::REPORTS.'/units?period_id=1', $tenant['headers'])->assertUnprocessable()
            ->assertJsonPath('code', 'TENANT_DATABASE_NOT_ACTIVE');
        $this->getJson('/api/budget/workspace-periods', $tenant['headers'])->assertOk();
    }

    private function unitWithBudget(array $headers, int $period, string $code, string $name, array $lines): int
    {
        $unit = $this->postJson('/api/workspace/units', ['code' => $code, 'name' => $name, 'is_active' => true], $headers)->assertCreated()->json('data.id');
        $annual = $this->postJson(self::BUDGET.'/requests', [
            'kind' => 'annual', 'unit_id' => $unit, 'period_id' => $period, 'title' => 'RAB Tahunan '.$name,
            'lines' => collect($lines)->map(fn ($amount, $item) => ['code' => $item, 'description' => $item === 'TR' ? 'Transportasi' : 'Perlengkapan', 'amount' => $amount])->values()->all(),
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson(self::BUDGET."/requests/$annual/submit", [], $headers)->assertOk();
        $this->postJson(self::BUDGET."/requests/$annual/approve", [], $headers)->assertOk();

        return $unit;
    }

    private function approvedActivity(array $headers, int $period, int $unit, string $title, array $lines): int
    {
        $items = collect($this->getJson(self::BUDGET."/items?kind=activity&period_id=$period&unit_id=$unit", $headers)->assertOk()->json('data'))->keyBy('code');
        $document = $this->postJson(self::BUDGET.'/requests', [
            'kind' => 'activity', 'unit_id' => $unit, 'period_id' => $period, 'title' => $title, 'activity_date' => '2026-01-10',
            'lines' => collect($lines)->map(fn ($amount, $item) => ['item_id' => $items[$item]['id'], 'description' => $title.' '.$item, 'amount' => $amount])->values()->all(),
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson(self::BUDGET."/requests/$document/submit", [], $headers)->assertOk();
        $this->postJson(self::BUDGET."/requests/$document/approve", [], $headers)->assertOk()->assertJsonPath('data.accounting.sync_status', 'delivered');

        return $document;
    }

    private function disburse(array $headers, array $tenant, int $document, int $expense, bool $post): ?int
    {
        $id = $this->getJson(self::BUDGET."/requests/$document", $headers)->json('data.accounting.id');
        $source = $this->getJson(self::QUEUE.'/'.$id, $headers)->json('data.source_lines');
        $tranche = $this->postJson(self::QUEUE."/$id/tranches", [
            'kind' => 'direct', 'disbursed_on' => '2026-01-15', 'cash_bank_account_id' => $tenant['accounts']['debit'], 'payment_reference' => 'TRF-'.$document,
            'lines' => collect($source)->map(fn ($line) => ['source_line_id' => $line['id'], 'amount' => $line['amount_minor'] / 100])->all(),
        ], $headers)->assertCreated()->json('data.tranches.0.id');
        if (! $post) {
            return null;
        }
        $lines = collect($source)->map(fn ($line) => ['source_line_id' => $line['id'], 'account_id' => $expense])->all();
        $payment = $this->postJson(self::QUEUE."/$id/tranches/$tranche/qc/approve", ['lines' => $lines], $headers)->assertOk()
            ->json('data.tranches.0.cash_payment.id');

        return $this->patchJson("/api/cash-bank/cash-payments/$payment/post", [], $headers)->assertOk()->json('data.journal_entry_id');
    }
}
