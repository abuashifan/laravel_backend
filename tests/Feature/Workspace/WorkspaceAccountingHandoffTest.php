<?php

namespace Tests\Feature\Workspace;

use App\Modules\CashBank\Models\CashPayment;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Shared\Models\CompanyUser;
use App\Shared\Models\User;
use App\Shared\Workspace\ActivityAccountingGateway;
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
            ->assertJsonPath('data.accounting.sync_status', 'delivered')->assertJsonPath('data.accounting.realized_minor', 0);
        $id = $approval->json('data.accounting.id');
        $this->postJson(self::BUDGET."/requests/$document/accounting-sync", [], $headers)->assertOk()->assertJsonPath('data.accounting.id', $id);
        $this->getJson(self::QUEUE, $headers)->assertOk()->assertJsonCount(1, 'data.data');
        $source = $this->getJson(self::QUEUE.'/'.$id, $headers)->assertOk()
            ->assertJsonPath('data.amount_minor', 6540)->assertJsonPath('data.workspace_document_id', $document)
            ->assertJsonPath('data.qc_status', 'pending')->json('data.source_lines');
        $this->assertSame(0, CashPayment::query()->count());

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5001', 'account_name' => 'Transportasi', 'account_type' => 'expense',
            'normal_balance' => 'debit', 'is_active' => true, 'is_cash_bank' => false,
        ]);
        $qc = ['lines' => [['source_line_id' => $source[0]['id'], 'account_id' => $expense->id]]];
        $this->postJson(self::QUEUE."/$id/qc/approve", $qc, $headers)->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_DISBURSEMENT_REQUIRED');
        $record = ['disbursed_on' => '2026-01-11', 'cash_bank_account_id' => $tenant['accounts']['debit'], 'payment_reference' => 'TRF-001'];
        $this->postJson(self::QUEUE."/$id/record", $record + ['amount' => 1], $headers)->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson(self::QUEUE."/$id/record", $record, $headers)->assertOk();
        $this->postJson(self::QUEUE."/$id/record", $record, $headers)->assertOk()->assertJsonCount(1, 'data.history');

        $this->postJson(self::QUEUE."/$id/qc/reject", ['note' => 'Periksa bukti lagi'], $headers)->assertOk();
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.qc_status', 'rejected')->assertJsonPath('data.accounting.disbursed_on', '2026-01-11')
            ->assertJsonPath('data.accounting.realized_minor', 0);
        $this->assertSame(0, JournalEntry::query()->count());
        $wrongLine = ['lines' => [['source_line_id' => 99999, 'account_id' => $expense->id]]];
        $this->postJson(self::QUEUE."/$id/qc/approve", $wrongLine, $headers)->assertUnprocessable()->assertJsonPath('code', 'WORKSPACE_DISBURSEMENT_LINE_MISMATCH');
        $payment = $this->postJson(self::QUEUE."/$id/qc/approve", $qc, $headers)->assertOk()
            ->assertJsonPath('data.qc_status', 'approved')->assertJsonPath('data.cash_payment.status', 'draft')->json('data.cash_payment_id');
        $this->postJson(self::QUEUE."/$id/qc/approve", $qc, $headers)->assertOk()->assertJsonPath('data.cash_payment_id', $payment);
        $this->assertSame(1, CashPayment::query()->count());
        $this->patchJson("/api/cash-bank/cash-payments/$payment", ['amount' => 1], $headers)
            ->assertConflict()->assertJsonPath('code', 'WORKSPACE_CASH_PAYMENT_MANAGED');

        $this->postJson(self::QUEUE."/$id/qc/reopen", ['note' => 'Tinjau ulang akun'], $headers)->assertOk()
            ->assertJsonPath('data.qc_status', 'pending')->assertJsonPath('data.cash_payment_id', null);
        $this->assertSame(0, CashPayment::query()->count());
        $payment = $this->postJson(self::QUEUE."/$id/qc/approve", $qc, $headers)->assertOk()->json('data.cash_payment_id');
        $journal = $this->patchJson("/api/cash-bank/cash-payments/$payment/post", [], $headers)->assertOk()->json('data.journal_entry_id');
        $this->patchJson("/api/cash-bank/cash-payments/$payment/post", [], $headers)->assertOk()->assertJsonPath('data.journal_entry_id', $journal);
        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(65.4, (float) JournalEntry::query()->findOrFail($journal)->lines()->sum('debit'));
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.journal_entry_id', $journal)->assertJsonPath('data.accounting.realized_minor', 6540);
        $this->postJson(self::QUEUE."/$id/qc/reopen", ['note' => 'Terlambat'], $headers)->assertConflict();

        $this->patchJson("/api/cash-bank/cash-payments/$payment/void", ['reason' => 'Koreksi pengeluaran'], $headers)->assertOk();
        $this->getJson(self::BUDGET."/requests/$document", $headers)->assertOk()
            ->assertJsonPath('data.accounting.payment_status', 'void')->assertJsonPath('data.accounting.realized_minor', 0)
            ->assertJsonPath('data.accounting.disbursed_on', '2026-01-11');
        $this->patchJson("/api/cash-bank/cash-payments/$payment/post", [], $headers)->assertUnprocessable();
    }

    public function test_failed_delivery_is_retriable_and_does_not_grant_accounting_permissions(): void
    {
        $tenant = $this->setUpTenant();
        $this->setUpProductTenant($tenant['company'], 'workspace', 'workspace_pilot');
        $headers = $tenant['headers'];
        $document = $this->submittedActivity($headers);
        $realGateway = app(ActivityAccountingGateway::class);
        $this->mock(ActivityAccountingGateway::class)->shouldReceive('receive')->once()->andReturnFalse();
        $this->postJson(self::BUDGET."/requests/$document/approve", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'approved')->assertJsonPath('data.accounting.sync_status', 'pending');
        $this->assertSame(0, DB::connection('tenant')->table('workspace_disbursements')->count());

        $this->app->instance(ActivityAccountingGateway::class, $realGateway);
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
