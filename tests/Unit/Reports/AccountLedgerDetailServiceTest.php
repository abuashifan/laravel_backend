<?php

namespace Tests\Unit\Reports;

use App\Data\Reports\AccountLedgerFilter;
use App\Models\Tenant\Department;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Project;
use App\Services\Reports\AccountLedgerDetailService;
use App\Services\Reports\LedgerBalanceCalculator;
use App\Services\Reports\LedgerFilterValidator;
use Tests\Feature\Journal\JournalTestCase;

class AccountLedgerDetailServiceTest extends JournalTestCase
{
    public function test_opening_period_ending_and_running_for_debit_normal_and_exclusions_and_dimension_filters(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $debitAccountId = (int) $ctx['accounts']['debit'];
        $creditAccountId = (int) $ctx['accounts']['credit'];

        $dept = Department::query()->create(['code' => 'OPS', 'name' => 'Ops', 'is_active' => true]);
        $project = Project::query()->create(['code' => 'PRJ', 'name' => 'Project', 'status' => 'active', 'is_active' => true]);

        // Opening: posted before start_date
        $jOpen = JournalEntry::query()->create([
            'journal_number' => 'JV-OPEN-1',
            'journal_date' => '2026-01-01',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jOpen->lines()->createMany([
            ['account_id' => $debitAccountId, 'debit' => 100, 'credit' => 0, 'line_order' => 1, 'department_id' => $dept->id],
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => 100, 'line_order' => 2],
        ]);

        // Period: posted in range
        $jPer = JournalEntry::query()->create([
            'journal_number' => 'JV-PER-1',
            'journal_date' => '2026-02-01',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jPer->lines()->createMany([
            ['account_id' => $debitAccountId, 'debit' => 50, 'credit' => 0, 'line_order' => 1, 'project_id' => $project->id],
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => 50, 'line_order' => 2],
        ]);

        // Excluded: void
        $jVoid = JournalEntry::query()->create([
            'journal_number' => 'JV-VOID-1',
            'journal_date' => '2026-02-02',
            'status' => 'void',
            'is_obsolete' => false,
        ]);
        $jVoid->lines()->createMany([
            ['account_id' => $debitAccountId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        // Excluded: approved
        $jApp = JournalEntry::query()->create([
            'journal_number' => 'JV-APP-1',
            'journal_date' => '2026-02-03',
            'status' => 'approved',
            'is_obsolete' => false,
        ]);
        $jApp->lines()->createMany([
            ['account_id' => $debitAccountId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        // Excluded: draft
        $jDraft = JournalEntry::query()->create([
            'journal_number' => 'JV-DRF-1',
            'journal_date' => '2026-02-04',
            'status' => 'draft',
            'is_obsolete' => false,
        ]);
        $jDraft->lines()->createMany([
            ['account_id' => $debitAccountId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        // Excluded: obsolete
        $jObs = JournalEntry::query()->create([
            'journal_number' => 'JV-OBS-1',
            'journal_date' => '2026-02-05',
            'status' => 'posted',
            'is_obsolete' => true,
        ]);
        $jObs->lines()->createMany([
            ['account_id' => $debitAccountId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        $service = new AccountLedgerDetailService(new LedgerBalanceCalculator(), new LedgerFilterValidator());

        $filter = AccountLedgerFilter::fromArray($debitAccountId, [
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'include_opening_balance' => true,
            'include_zero_balance' => false,
            'sort_direction' => 'asc',
        ]);

        $detail = $service->getDetail($debitAccountId, $filter);

        $this->assertTrue((bool) ($detail['valid'] ?? false));
        $this->assertSame($debitAccountId, (int) $detail['account']['id']);
        $this->assertSame(100.0, (float) $detail['opening_balance']['balance']);
        $this->assertSame(50.0, (float) $detail['period_totals']['movement_balance']);
        $this->assertSame(150.0, (float) $detail['ending_balance']);
        $this->assertCount(1, (array) $detail['lines']);
        $this->assertSame(150.0, (float) $detail['lines'][0]['running_balance']);

        // Department filter applies to opening and period.
        $filterDept = AccountLedgerFilter::fromArray($debitAccountId, [
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'department_id' => $dept->id,
        ]);
        $detailDept = $service->getDetail($debitAccountId, $filterDept);
        $this->assertSame(100.0, (float) $detailDept['opening_balance']['balance']);
        $this->assertSame(0.0, (float) $detailDept['period_totals']['movement_balance']);

        // Project filter applies to opening and period.
        $filterProject = AccountLedgerFilter::fromArray($debitAccountId, [
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'project_id' => $project->id,
        ]);
        $detailProject = $service->getDetail($debitAccountId, $filterProject);
        $this->assertSame(0.0, (float) $detailProject['opening_balance']['balance']);
        $this->assertSame(50.0, (float) $detailProject['period_totals']['movement_balance']);
    }

    public function test_credit_normal_account_running_balance_uses_credit_minus_debit(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $creditAccountId = (int) $ctx['accounts']['credit'];
        $debitAccountId = (int) $ctx['accounts']['debit'];

        $j = JournalEntry::query()->create([
            'journal_number' => 'JV-CR-1',
            'journal_date' => '2026-03-01',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $j->lines()->createMany([
            ['account_id' => $creditAccountId, 'debit' => 0, 'credit' => 20, 'line_order' => 1],
            ['account_id' => $debitAccountId, 'debit' => 20, 'credit' => 0, 'line_order' => 2],
        ]);

        $service = new AccountLedgerDetailService(new LedgerBalanceCalculator(), new LedgerFilterValidator());

        $filter = AccountLedgerFilter::fromArray($creditAccountId, [
            'start_date' => '2026-03-01',
            'end_date' => '2026-03-31',
        ]);

        $detail = $service->getDetail($creditAccountId, $filter);
        $this->assertTrue((bool) ($detail['valid'] ?? false));
        $this->assertSame(20.0, (float) $detail['lines'][0]['running_balance']);
    }
}

