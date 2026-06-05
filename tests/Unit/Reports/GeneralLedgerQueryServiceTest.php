<?php

namespace Tests\Unit\Reports;

use App\Data\Reports\LedgerFilter;
use App\Models\Tenant\Department;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Project;
use App\Services\Reports\GeneralLedgerQueryService;
use App\Services\Reports\LedgerBalanceCalculator;
use App\Services\Reports\LedgerFilterValidator;
use Tests\Feature\Journal\JournalTestCase;

class GeneralLedgerQueryServiceTest extends JournalTestCase
{
    public function test_signed_amount_rules_for_debit_and_credit_normal_accounts(): void
    {
        $calc = new LedgerBalanceCalculator();

        $this->assertSame(70.0, $calc->signedAmount(100, 30, 'debit'));
        $this->assertSame(70.0, $calc->signedAmount(30, 100, 'credit'));
    }

    public function test_opening_period_ending_and_running_balance_calculation_and_exclusions(): void
    {
        $ctx = $this->setUpTenant(role: 'owner', accountingSettingOverrides: [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
        ]);

        $accountId = (int) $ctx['accounts']['debit'];

        $dept = Department::query()->create(['code' => 'OPS', 'name' => 'Ops', 'is_active' => true]);
        $project = Project::query()->create(['code' => 'PRJ', 'name' => 'Project', 'status' => 'active', 'is_active' => true]);

        // Opening: posted before start_date (included)
        $j1 = JournalEntry::query()->create([
            'journal_number' => 'JV-OPEN-1',
            'journal_date' => '2026-01-01',
            'status' => 'posted',
            'is_obsolete' => false,
            'source_type' => 'manual_journal',
            'source_number' => 'JV-OPEN-1',
            'source_module' => 'journal',
        ]);
        $j1->lines()->createMany([
            ['account_id' => $accountId, 'debit' => 100, 'credit' => 0, 'line_order' => 1, 'department_id' => $dept->id],
            ['account_id' => (int) $ctx['accounts']['credit'], 'debit' => 0, 'credit' => 100, 'line_order' => 2],
        ]);

        // Period: posted in range (included)
        $j2 = JournalEntry::query()->create([
            'journal_number' => 'JV-PER-1',
            'journal_date' => '2026-02-01',
            'status' => 'posted',
            'is_obsolete' => false,
            'source_type' => 'manual_journal',
            'source_number' => 'JV-PER-1',
            'source_module' => 'journal',
        ]);
        $j2->lines()->createMany([
            ['account_id' => $accountId, 'debit' => 50, 'credit' => 0, 'line_order' => 1, 'project_id' => $project->id],
            ['account_id' => (int) $ctx['accounts']['credit'], 'debit' => 0, 'credit' => 50, 'line_order' => 2],
        ]);

        // Excluded: void
        $jVoid = JournalEntry::query()->create([
            'journal_number' => 'JV-VOID-1',
            'journal_date' => '2026-02-15',
            'status' => 'void',
            'is_obsolete' => false,
        ]);
        $jVoid->lines()->createMany([
            ['account_id' => $accountId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => (int) $ctx['accounts']['credit'], 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        // Excluded: draft
        $jDraft = JournalEntry::query()->create([
            'journal_number' => 'JV-DRF-1',
            'journal_date' => '2026-02-16',
            'status' => 'draft',
            'is_obsolete' => false,
        ]);
        $jDraft->lines()->createMany([
            ['account_id' => $accountId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => (int) $ctx['accounts']['credit'], 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        // Excluded: approved
        $jApproved = JournalEntry::query()->create([
            'journal_number' => 'JV-APP-1',
            'journal_date' => '2026-02-17',
            'status' => 'approved',
            'is_obsolete' => false,
        ]);
        $jApproved->lines()->createMany([
            ['account_id' => $accountId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => (int) $ctx['accounts']['credit'], 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        // Excluded: obsolete
        $jObs = JournalEntry::query()->create([
            'journal_number' => 'JV-OBS-1',
            'journal_date' => '2026-02-18',
            'status' => 'posted',
            'is_obsolete' => true,
        ]);
        $jObs->lines()->createMany([
            ['account_id' => $accountId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
            ['account_id' => (int) $ctx['accounts']['credit'], 'debit' => 0, 'credit' => 999, 'line_order' => 2],
        ]);

        $service = new GeneralLedgerQueryService(
            new LedgerBalanceCalculator(),
            new LedgerFilterValidator(),
        );

        $filter = LedgerFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'account_id' => $accountId,
            'sort_by' => 'journal_date',
            'sort_direction' => 'asc',
        ]);

        $ledger = $service->getLedger($filter);

        $this->assertTrue((bool) ($ledger['valid'] ?? false));
        $this->assertSame(100.0, (float) $ledger['opening_balance']['balance']);
        $this->assertSame(50.0, (float) $ledger['period_totals']['balance']);
        $this->assertSame(150.0, (float) $ledger['ending_balance']);
        $this->assertCount(1, (array) $ledger['lines']);
        $this->assertSame(150.0, (float) $ledger['lines'][0]['running_balance']);

        // Dimension filters apply to opening + period.
        $filterDept = LedgerFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'account_id' => $accountId,
            'department_id' => $dept->id,
        ]);
        $ledgerDept = $service->getLedger($filterDept);
        $this->assertSame(100.0, (float) $ledgerDept['opening_balance']['balance']);
        $this->assertSame(0.0, (float) $ledgerDept['period_totals']['balance']);

        $filterProject = LedgerFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'account_id' => $accountId,
            'project_id' => $project->id,
        ]);
        $ledgerProject = $service->getLedger($filterProject);
        $this->assertSame(0.0, (float) $ledgerProject['opening_balance']['balance']);
        $this->assertSame(50.0, (float) $ledgerProject['period_totals']['balance']);
    }
}

