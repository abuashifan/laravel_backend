<?php

namespace Tests\Feature\CashBank;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use Tests\Feature\Journal\JournalTestCase;

/**
 * Gap B — Cash Payment disambungkan ke `BudgetWarningService`, pola yang sama
 * dengan Journal. Baris cash payment sudah membawa `department_id`/`project_id`
 * sejak lama, jadi tidak ada penyesuaian dimensi seperti di Sales Invoice.
 */
class CashPaymentBudgetWarningTest extends JournalTestCase
{
    private function budgetLineFor(array $ctx, ChartOfAccount $account, Department $department, float $amount): void
    {
        $period = BudgetPeriod::query()->create([
            'company_id' => $ctx['company']->id,
            'name' => 'Anggaran 2026',
            'fiscal_year' => 2026,
            'period_from' => '2026-01-01',
            'period_to' => '2026-12-31',
            'status' => 'open',
            'created_by' => $ctx['user']->id,
        ]);

        $submission = BudgetSubmission::query()->create([
            'company_id' => $ctx['company']->id,
            'budget_period_id' => $period->id,
            'department_id' => $department->id,
            'status' => 'approved',
            'is_active' => true,
            'version_no' => 1,
            'revision_number' => 1,
            'created_by' => $ctx['user']->id,
        ]);

        BudgetLine::query()->create([
            'budget_submission_id' => $submission->id,
            'account_id' => $account->id,
            'department_id' => $department->id,
            'direction' => 'expense',
            'amount' => $amount,
        ]);
    }

    public function test_posting_a_cash_payment_that_exceeds_the_budget_returns_a_warning(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $department = Department::query()->create(['code' => 'OPS', 'name' => 'Operational', 'is_active' => true]);
        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
        ]);

        // Pagu 1.000; pembayaran ini sendiri 1.500 — sudah melebihi tanpa
        // riwayat pengeluaran lain sama sekali.
        $this->budgetLineFor($ctx, $expense, $department, 1_000);

        $payload = [
            'payment_date' => '2026-01-11',
            'cash_bank_account_id' => $ctx['accounts']['debit'],
            'amount' => 1_500,
            'notes' => 'Belanja operasional',
            'lines' => [
                ['account_id' => $expense->id, 'amount' => 1_500, 'department_id' => $department->id, 'description' => 'Belanja', 'line_order' => 1],
            ],
        ];

        $created = $this->postJson('/api/cash-bank/cash-payments', $payload, $ctx['headers'])->assertStatus(201)->json('data');

        $res = $this->patchJson('/api/cash-bank/cash-payments/'.$created['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200);

        $warnings = $res->json('meta.warnings');

        $this->assertCount(1, $warnings);
        $this->assertSame($expense->id, $warnings[0]['account_id']);
        // postHoc: jurnal sudah diposting saat check() berjalan, jadi new_total
        // WAJIB sama dengan nominal pembayaran itu sendiri — bukan dua kalinya.
        $this->assertEqualsWithDelta(1_500.0, $warnings[0]['new_total'], 0.01);
        $this->assertSame('over_budget', $warnings[0]['state']);
    }

    public function test_posting_a_cash_payment_within_budget_returns_no_warning(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $department = Department::query()->create(['code' => 'OPS', 'name' => 'Operational', 'is_active' => true]);
        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
        ]);

        $this->budgetLineFor($ctx, $expense, $department, 10_000);

        $payload = [
            'payment_date' => '2026-01-11',
            'cash_bank_account_id' => $ctx['accounts']['debit'],
            'amount' => 1_000,
            'lines' => [
                ['account_id' => $expense->id, 'amount' => 1_000, 'department_id' => $department->id, 'description' => 'Belanja', 'line_order' => 1],
            ],
        ];

        $created = $this->postJson('/api/cash-bank/cash-payments', $payload, $ctx['headers'])->assertStatus(201)->json('data');

        $res = $this->patchJson('/api/cash-bank/cash-payments/'.$created['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame([], $res->json('meta.warnings'));
    }
}
