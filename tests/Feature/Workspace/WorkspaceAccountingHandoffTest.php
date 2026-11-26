<?php

namespace Tests\Feature\Workspace;

use App\Modules\CashBank\Models\CashPayment;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\TenantDatabase;
use App\Shared\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Journal\JournalTestCase;

class WorkspaceAccountingHandoffTest extends JournalTestCase
{
    private const BUDGET = '/api/workspace/budget';

    private const QUEUE = '/api/cash-bank/workspace-disbursements';

    public function test_approved_activity_becomes_one_accounting_draft_and_requires_qc_before_posting(): void
    {
        $tenant = $this->setUpTenant();
        $this->setUpProductTenant($tenant['company'], 'workspace', 'workspace_pilot');
        $headers = $tenant['headers'];
        $document = $this->submittedActivity($headers);
        $approval = $this->postJson(self::BUDGET."/requests/$document/approve", [], $headers)->assertOk()
            ->assertJsonPath('data.accounting.sync_status', 'delivered')->assertJsonPath('data.accounting.realized_minor', 0)
            ->assertJsonPath('data.accounting.state', 'not_disbursed');
        $id = $approval->json('data.accounting.id');
        $this->postJson(self::BUDGET."/requests/$document/accounting-sync", [], $headers)->assertOk()->assertJsonPath('data.accounting.id', $id);
        $this->getJson(self::QUEUE, $headers)->assertOk()->assertJsonCount(1, 'data.data');
        $source = $this->getJson(self::QUEUE.'/'.$id, $headers)->assertOk()
            ->assertJsonPath('data.amount_minor', 6540)->assertJsonPath('data.workspace_document_id', $document)
            ->assertJsonPath('data.state', 'not_disbursed')->assertJsonPath('data.source_lines.0.remaining_minor', 6540)->json('data.source_lines');
        $this->assertSame(0, CashPayment::query()->count());

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5001', 'account_name' => 'Transportasi', 'account_type' => 'expense',
            'normal_balance' => 'debit', 'is_active' => true, 'is_cash_bank' => false,
        ]);
        $qc = ['lines' => [['source_line_id' => $source[0]['id'], 'account_id' => $expense->id]]];
        $record = ['kind' => 'direct', 'disbursed_on' => '2026-01-11', 'cash_bank_account_id' => $tenant['accounts']['debit'],
            'payment_reference' => 'TRF-001', 'lines' => [['source_line_id' => $source[0]['id'], 'amount' => '65.40']]];
        $this->postJson(self::QUEUE."/$id/tranches", $record + ['amount' => 1], $headers)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson(self::QUEUE."/$id/tranches", ['lines' => [['source_line_id' => $source[0]['id'], 'amount' => '65.41']]] + $record, $headers)
            ->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_TRANCHE_EXCEEDS_BUDGET');
        $tranche = $this->postJson(self::QUEUE."/$id/tranches", $record, $headers)->assertCreated()
            ->assertJsonPath('data.state', 'awaiting_qc')->assertJsonPath('data.tranches.0.sequence', 1)->json('data.tranches.0.id');
        $this->postJson(self::QUEUE."/$id/tranches", $record, $headers)->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_TRANCHE_EXCEEDS_BUDGET');
        $path = self::QUEUE."/$id/tranches/$tranche";

        $this->postJson("$path/qc/reject", ['note' => 'Periksa bukti lagi'], $headers)->assertOk()->assertJsonPath('data.state', 'returned');
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.state', 'returned')->assertJsonPath('data.accounting.tranches.0.disbursed_on', '2026-01-11')
            ->assertJsonPath('data.accounting.realized_minor', 0)->assertJsonPath('data.accounting.disbursed_minor', 6540);
        $this->assertSame(0, JournalEntry::query()->count());
        $wrongLine = ['lines' => [['source_line_id' => 99999, 'account_id' => $expense->id]]];
        $this->postJson("$path/qc/approve", $wrongLine, $headers)->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_DISBURSEMENT_LINE_MISMATCH');
        $payment = $this->postJson("$path/qc/approve", $qc, $headers)->assertOk()
            ->assertJsonPath('data.tranches.0.qc_status', 'approved')->assertJsonPath('data.tranches.0.cash_payment.status', 'draft')->json('data.tranches.0.cash_payment.id');
        $this->postJson("$path/qc/approve", $qc, $headers)->assertOk()->assertJsonPath('data.tranches.0.cash_payment.id', $payment);
        $this->assertSame(1, CashPayment::query()->count());
        $this->patchJson("/api/cash-bank/cash-payments/$payment", [
            'payment_date' => $record['disbursed_on'], 'cash_bank_account_id' => $record['cash_bank_account_id'], 'amount' => 1,
            'lines' => [['account_id' => $expense->id, 'amount' => 1]],
        ], $headers)
            ->assertConflict()->assertJsonPath('code', 'WORKSPACE_CASH_PAYMENT_MANAGED');

        $this->postJson("$path/qc/reopen", ['note' => 'Tinjau ulang akun'], $headers)->assertOk()
            ->assertJsonPath('data.tranches.0.qc_status', 'pending')->assertJsonPath('data.tranches.0.cash_payment', null);
        $this->assertSame(0, CashPayment::query()->count());
        $payment = $this->postJson("$path/qc/approve", $qc, $headers)->assertOk()->json('data.tranches.0.cash_payment.id');
        $journal = $this->patchJson("/api/cash-bank/cash-payments/$payment/post", [], $headers)->assertOk()->json('data.journal_entry_id');
        $this->patchJson("/api/cash-bank/cash-payments/$payment/post", [], $headers)->assertOk()->assertJsonPath('data.journal_entry_id', $journal);
        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(65.4, (float) JournalEntry::query()->findOrFail($journal)->lines()->sum('debit'));
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.tranches.0.journal.id', $journal)->assertJsonPath('data.accounting.realized_minor', 6540)
            ->assertJsonPath('data.accounting.state', 'complete');
        $this->postJson("$path/qc/reopen", ['note' => 'Terlambat'], $headers)->assertConflict();

        $this->patchJson("/api/cash-bank/cash-payments/$payment/void", ['reason' => 'Koreksi pengeluaran'], $headers)->assertOk();
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.state', 'payment_void')->assertJsonPath('data.accounting.realized_minor', 0)
            ->assertJsonPath('data.accounting.tranches.0.disbursed_on', '2026-01-11');
        $this->patchJson("/api/cash-bank/cash-payments/$payment/post", [], $headers)->assertUnprocessable();
    }

    public function test_failed_delivery_is_retriable_and_does_not_grant_accounting_permissions(): void
    {
        $tenant = $this->setUpTenant();
        $this->setUpProductTenant($tenant['company'], 'workspace', 'workspace_pilot');
        $headers = $tenant['headers'];
        $document = $this->submittedActivity($headers);
        $accounting = TenantDatabase::forProduct('accounting')->where('company_id', $tenant['company']->id)->firstOrFail();
        $accounting->update(['status' => 'inactive']);
        $this->postJson(self::BUDGET."/requests/$document/approve", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.accounting.sync_status', 'pending');
        $this->assertSame(0, DB::connection('tenant')->table('workspace_disbursements')->count());

        $accounting->update(['status' => 'active']);
        $this->postJson(self::BUDGET."/requests/$document/accounting-sync", [], $headers)->assertOk()
            ->assertJsonPath('data.accounting.sync_status', 'delivered');
        $this->getJson(self::QUEUE, $headers)->assertOk()->assertJsonCount(1, 'data.data');

        $staff = User::factory()->create(['status' => 'active']);
        $membership = CompanyUser::query()->create([
            'company_id' => $tenant['company']->id, 'user_id' => $staff->id, 'role' => 'viewer', 'status' => 'active', 'full_access' => false,
        ]);
        $this->assignTestPermissions($membership, ['workspace.budget.approve_activity']);
        Sanctum::actingAs($staff, ['client']);
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk();
        $this->getJson(self::QUEUE, $headers)->assertForbidden();
    }

    public function test_returned_and_voided_disbursements_have_a_correction_path(): void
    {
        $tenant = $this->setUpTenant();
        $this->setUpProductTenant($tenant['company'], 'workspace', 'workspace_pilot');
        $headers = $tenant['headers'];
        $document = $this->submittedActivity($headers);
        $id = $this->postJson(self::BUDGET."/requests/$document/approve", [], $headers)->assertOk()->json('data.accounting.id');
        $source = $this->getJson(self::QUEUE.'/'.$id, $headers)->assertOk()
            ->assertJsonPath('data.suggested_lines.0.account', null)->json('data.source_lines');
        $expense = ChartOfAccount::query()->create([
            'account_code' => '5001', 'account_name' => 'Transportasi', 'account_type' => 'expense',
            'normal_balance' => 'debit', 'is_active' => true, 'is_cash_bank' => false,
        ]);
        $record = ['kind' => 'direct', 'disbursed_on' => '2026-01-11', 'cash_bank_account_id' => $tenant['accounts']['debit'],
            'payment_reference' => 'TRF-001', 'lines' => [['source_line_id' => $source[0]['id'], 'amount' => '65.40']]];
        $qc = ['lines' => [['source_line_id' => $source[0]['id'], 'account_id' => $expense->id]]];

        // QC dikembalikan, lalu tahap dikoreksi: kembali ke antrean QC.
        $tranche = $this->postJson(self::QUEUE."/$id/tranches", $record, $headers)->assertCreated()->json('data.tranches.0.id');
        $path = self::QUEUE."/$id/tranches/$tranche";
        $this->postJson("$path/qc/reject", ['note' => 'Bukti transfer salah'], $headers)->assertOk()->assertJsonPath('data.state', 'returned');
        $this->getJson(self::QUEUE.'?state=awaiting_qc', $headers)->assertOk()->assertJsonCount(0, 'data.data');
        $this->putJson($path, ['payment_reference' => 'TRF-002'] + $record, $headers)->assertOk()
            ->assertJsonPath('data.tranches.0.qc_status', 'pending')->assertJsonPath('data.tranches.0.payment_reference', 'TRF-002');
        $this->getJson(self::QUEUE.'?state=awaiting_qc', $headers)->assertOk()->assertJsonCount(1, 'data.data');

        // Kas keluar posted lalu di-void: QC bisa dibuka kembali dan membuat draf baru.
        $first = $this->postJson("$path/qc/approve", $qc, $headers)->assertOk()->json('data.tranches.0.cash_payment.id');
        $this->patchJson("/api/cash-bank/cash-payments/$first/post", [], $headers)->assertOk();
        $this->postJson("$path/qc/reopen", ['note' => 'Masih posted'], $headers)->assertConflict();
        $this->patchJson("/api/cash-bank/cash-payments/$first/void", ['reason' => 'Salah tanggal'], $headers)->assertOk();
        $this->postJson("$path/qc/reopen", ['note' => 'Koreksi tanggal pencairan'], $headers)->assertOk()
            ->assertJsonPath('data.tranches.0.qc_status', 'pending')->assertJsonPath('data.tranches.0.cash_payment', null)
            ->assertJsonPath('data.history.4.details.previous_cash_payment_status', 'void');
        $this->assertSame('void', CashPayment::query()->findOrFail($first)->status);
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.state', 'awaiting_qc')->assertJsonPath('data.accounting.realized_minor', 0);

        // Akun QC sebelumnya untuk pos yang sama diusulkan, kas keluar void tidak dipakai.
        $this->getJson(self::QUEUE.'/'.$id, $headers)->assertOk()->assertJsonPath('data.suggested_lines.0.account', null);
        $this->putJson($path, ['disbursed_on' => '2026-01-12'] + $record, $headers)->assertOk();
        $second = $this->postJson("$path/qc/approve", $qc, $headers)->assertOk()->json('data.tranches.0.cash_payment.id');
        $this->assertNotSame($first, $second);
        $this->getJson(self::QUEUE.'/'.$id, $headers)->assertOk()
            ->assertJsonPath('data.suggested_lines.0.account.value', $expense->id)
            ->assertJsonPath('data.suggested_lines.0.account.label', '5001 · Transportasi');
        $journal = $this->patchJson("/api/cash-bank/cash-payments/$second/post", [], $headers)->assertOk()->json('data.journal_entry_id');
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.tranches.0.journal.id', $journal)->assertJsonPath('data.accounting.realized_minor', 6540)
            ->assertJsonPath('data.accounting.tranches.0.disbursed_on', '2026-01-12');
        $this->assertSame(2, CashPayment::query()->count());
    }

    private function submittedActivity(array $headers): int
    {
        $path = self::BUDGET;
        $period = $this->postJson("$path/periods", ['name' => 'Pagu 2026', 'period_from' => '2026-01-01'], $headers)->assertCreated()->json('data.id');
        $unit = $this->postJson('/api/workspace/units', ['code' => 'A', 'name' => 'Unit A', 'is_active' => true], $headers)->assertCreated()->json('data.id');
        $annual = $this->postJson("$path/requests", [
            'kind' => 'annual', 'unit_id' => $unit, 'period_id' => $period, 'title' => 'RAB Tahunan',
            'lines' => [['code' => 'TR', 'description' => 'Transportasi', 'amount' => '100.40']],
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson("$path/requests/$annual/submit", [], $headers)->assertOk();
        $this->postJson("$path/requests/$annual/approve", [], $headers)->assertOk();
        $item = $this->getJson("$path/items?kind=activity&period_id=$period&unit_id=$unit", $headers)->assertOk()->json('data.0.id');
        $activity = $this->postJson("$path/requests", [
            'kind' => 'activity', 'unit_id' => $unit, 'period_id' => $period, 'title' => 'Kunjungan', 'activity_date' => '2026-01-11',
            'lines' => [['item_id' => $item, 'description' => 'Ongkos', 'amount' => '65.40']],
        ], $headers)->assertCreated()->json('data.id');
        $this->postJson("$path/requests/$activity/submit", [], $headers)->assertOk();

        return $activity;
    }
}
