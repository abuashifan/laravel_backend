<?php

namespace Tests\Unit\Reports;

use App\Data\Reports\TrialBalanceFilter;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Department;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\Project;
use App\Services\Reports\LedgerFilterValidator;
use App\Services\Reports\TrialBalanceCalculator;
use App\Services\Reports\TrialBalanceService;
use Tests\Feature\Journal\JournalTestCase;

class TrialBalanceServiceTest extends JournalTestCase
{
    public function test_trial_balance_calculates_opening_period_ending_and_balance_check_and_filters(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $cashId = (int) $ctx['accounts']['debit']; // debit normal (asset)
        $revId = (int) $ctx['accounts']['credit']; // credit normal (revenue)

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5100',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $inactive = ChartOfAccount::query()->create([
            'account_code' => '9999',
            'account_name' => 'Inactive Account',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => false,
            'is_system_default' => false,
        ]);

        $dept = Department::query()->create(['code' => 'OPS', 'name' => 'Ops', 'is_active' => true]);
        $project = Project::query()->create(['code' => 'PRJ', 'name' => 'Project', 'status' => 'active', 'is_active' => true]);

        // Opening posted (before start_date): cash debit 100, revenue credit 100, tagged dept
        $jOpen = JournalEntry::query()->create([
            'journal_number' => 'JV-OPEN',
            'journal_date' => '2026-01-01',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jOpen->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 100, 'credit' => 0, 'line_order' => 1, 'department_id' => $dept->id],
            ['account_id' => $revId, 'debit' => 0, 'credit' => 100, 'line_order' => 2, 'department_id' => $dept->id],
        ]);

        // Period posted: cash credit 20, expense debit 20, tagged project
        $jPer = JournalEntry::query()->create([
            'journal_number' => 'JV-PER',
            'journal_date' => '2026-02-01',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        $jPer->lines()->createMany([
            ['account_id' => $expense->id, 'debit' => 20, 'credit' => 0, 'line_order' => 1, 'project_id' => $project->id],
            ['account_id' => $cashId, 'debit' => 0, 'credit' => 20, 'line_order' => 2, 'project_id' => $project->id],
        ]);

        // Note: movement-on-inactive coverage is handled in API tests; unit test focuses on totals math and filters.

        // Excluded journals
        foreach ([
            ['JV-VOID', 'void', false],
            ['JV-DRF', 'draft', false],
            ['JV-APP', 'approved', false],
            ['JV-OBS', 'posted', true],
        ] as [$num, $status, $obsolete]) {
            $j = JournalEntry::query()->create([
                'journal_number' => $num,
                'journal_date' => '2026-02-03',
                'status' => $status,
                'is_obsolete' => (bool) $obsolete,
            ]);
            $j->lines()->createMany([
                ['account_id' => $cashId, 'debit' => 999, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $revId, 'debit' => 0, 'credit' => 999, 'line_order' => 2],
            ]);
        }

        $service = new TrialBalanceService(new TrialBalanceCalculator(), new LedgerFilterValidator());

        $filter = TrialBalanceFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'include_zero_balance' => false,
        ]);

        $periodMap = $service->getPeriodTotalsByAccount($filter);
        $this->assertSame(20.0, (float) ($periodMap[$cashId]['credit'] ?? 0));

        $tb = $service->getTrialBalance($filter);
        $this->assertTrue((bool) ($tb['valid'] ?? false));

        $totals = (array) $tb['totals'];
        $this->assertTrue((bool) ($totals['is_balanced'] ?? false));

        // Cash: opening 100 debit, period credit 20, ending debit 80
        $cashRow = collect($tb['accounts'])->firstWhere('account_id', $cashId);
        $this->assertSame(100.0, (float) $cashRow['opening_debit']);
        $this->assertSame(20.0, (float) $cashRow['period_credit']);
        $this->assertSame(80.0, (float) $cashRow['ending_debit']);

        // Revenue: opening credit 100, no period, ending credit 100
        $revRow = collect($tb['accounts'])->firstWhere('account_id', $revId);
        $this->assertSame(100.0, (float) $revRow['opening_credit']);
        $this->assertSame(100.0, (float) $revRow['ending_credit']);

        // include_inactive_accounts works (inactive account is included when true)
        $tbInactive = $service->getTrialBalance(TrialBalanceFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'include_inactive_accounts' => true,
            'include_zero_balance' => true,
        ]));
        $this->assertTrue((bool) ($tbInactive['valid'] ?? false));
        $this->assertNotNull(collect($tbInactive['accounts'])->firstWhere('account_id', (int) $inactive->id));

        // include_zero_balance true should include a zero-movement account (create one active)
        $zero = ChartOfAccount::query()->create([
            'account_code' => '7000',
            'account_name' => 'Zero',
            'account_type' => 'revenue',
            'normal_balance' => 'credit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $tb2 = $service->getTrialBalance(TrialBalanceFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'include_zero_balance' => true,
        ]));
        $this->assertTrue((bool) ($tb2['valid'] ?? false));
        $this->assertNotNull(collect($tb2['accounts'])->firstWhere('account_id', (int) $zero->id));

        // account_type filter works
        $tbExpense = $service->getTrialBalance(TrialBalanceFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'account_type' => 'expense',
            'include_zero_balance' => true,
        ]));
        $this->assertTrue((bool) ($tbExpense['valid'] ?? false));
        $this->assertCount(1, (array) $tbExpense['accounts']);
        $this->assertSame('expense', (string) $tbExpense['accounts'][0]['account_type']);

        // department filter limits opening (uses dept on opening journal)
        $tbDept = $service->getTrialBalance(TrialBalanceFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'department_id' => $dept->id,
            'include_zero_balance' => true,
        ]));
        $cashDept = collect($tbDept['accounts'])->firstWhere('account_id', $cashId);
        $this->assertSame(100.0, (float) $cashDept['opening_debit']);
        $this->assertSame(0.0, (float) $cashDept['period_credit']);

        // project filter limits period (uses project on period journal)
        $tbProject = $service->getTrialBalance(TrialBalanceFilter::fromArray([
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'project_id' => $project->id,
            'include_zero_balance' => true,
        ]));
        $cashProject = collect($tbProject['accounts'])->firstWhere('account_id', $cashId);
        $this->assertSame(0.0, (float) $cashProject['opening_debit']);
        $this->assertSame(20.0, (float) $cashProject['period_credit']);
    }

    public function test_is_balanced_false_when_bad_data_unbalanced(): void
    {
        $ctx = $this->setUpTenant(role: 'owner');

        $cashId = (int) $ctx['accounts']['debit'];
        $revId = (int) $ctx['accounts']['credit'];

        $jBad = JournalEntry::query()->create([
            'journal_number' => 'JV-BAD',
            'journal_date' => '2026-04-01',
            'status' => 'posted',
            'is_obsolete' => false,
        ]);
        // Intentionally unbalanced posted journal (bad data)
        $jBad->lines()->createMany([
            ['account_id' => $cashId, 'debit' => 10, 'credit' => 0, 'line_order' => 1],
            ['account_id' => $revId, 'debit' => 0, 'credit' => 9, 'line_order' => 2],
        ]);

        $service = new TrialBalanceService(new TrialBalanceCalculator(), new LedgerFilterValidator());
        $tb = $service->getTrialBalance(TrialBalanceFilter::fromArray([
            'start_date' => '2026-04-01',
            'end_date' => '2026-04-30',
            'include_zero_balance' => true,
        ]));

        $this->assertTrue((bool) ($tb['valid'] ?? false));
        $this->assertFalse((bool) ($tb['totals']['is_balanced'] ?? true));
    }
}
