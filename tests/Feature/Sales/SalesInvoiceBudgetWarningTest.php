<?php

namespace Tests\Feature\Sales;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;
use App\Shared\Models\CompanyAccountingSetting;

/**
 * Gap B — Sales Invoice disambungkan ke `BudgetWarningService`, dari sisi
 * pendapatan. Dibaca dari `revenue_account_id` baris invoice, bukan dari
 * debit/kredit jurnal — baris invoice SELALU pendapatan, jadi tidak ada
 * ambiguitas arah yang perlu dihitung ulang seperti di Journal.
 */
class SalesInvoiceBudgetWarningTest extends SalesTestCase
{
    private function bootPostingTenant(): array
    {
        $ctx = $this->setUpTenant();
        $this->seedPostingMappings();

        CompanyAccountingSetting::query()->where('company_id', $ctx['company']->id)->update([
            'transaction_workflow_mode' => 'simple_auto_post',
            'auto_post_transactions' => true,
            'approval_enabled' => false,
        ]);

        return $ctx;
    }

    private function budgetLineFor(array $ctx, ChartOfAccount $revenueAccount, Department $department, float $amount): void
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
            'account_id' => $revenueAccount->id,
            'department_id' => $department->id,
            'direction' => 'revenue',
            'amount' => $amount,
        ]);
    }

    public function test_posting_an_invoice_that_exceeds_the_revenue_target_returns_a_favorable_warning(): void
    {
        $ctx = $this->bootPostingTenant();
        $department = Department::query()->create(['code' => 'OPS', 'name' => 'Operational', 'is_active' => true]);
        $revenueAccount = ChartOfAccount::query()->where('account_code', '4100')->firstOrFail();

        // Target pendapatan 1.000; invoice ini sendiri 1.500 — melampaui target.
        $this->budgetLineFor($ctx, $revenueAccount, $department, 1_000);

        $res = $this->postJson('/api/sales/invoices', [
            'customer_id' => $this->createCustomer(),
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => false,
            'tax_included' => false,
            'lines' => [[
                'description' => 'Jasa renovasi',
                'quantity' => 1,
                'unit_price' => 1_500,
                'tax_rate' => 0,
                'department_id' => $department->id,
            ]],
        ], $ctx['headers'])->assertStatus(201);

        $warnings = $res->json('meta.warnings');

        $this->assertCount(1, $warnings);
        $this->assertSame($revenueAccount->id, $warnings[0]['account_id']);
        $this->assertEqualsWithDelta(1_500.0, $warnings[0]['new_total'], 0.01);
        // Melampaui TARGET pendapatan adalah kabar baik — state-nya wajib
        // under_budget (favorable), bukan over_budget seperti pada beban.
        $this->assertSame('under_budget', $warnings[0]['state']);
        $this->assertSame('revenue', $warnings[0]['direction']);
    }

    public function test_posting_an_invoice_below_the_revenue_target_returns_no_warning(): void
    {
        $ctx = $this->bootPostingTenant();
        $department = Department::query()->create(['code' => 'OPS', 'name' => 'Operational', 'is_active' => true]);
        $revenueAccount = ChartOfAccount::query()->where('account_code', '4100')->firstOrFail();

        $this->budgetLineFor($ctx, $revenueAccount, $department, 10_000);

        $res = $this->postJson('/api/sales/invoices', [
            'customer_id' => $this->createCustomer(),
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => false,
            'tax_included' => false,
            'lines' => [[
                'description' => 'Jasa renovasi',
                'quantity' => 1,
                'unit_price' => 1_000,
                'tax_rate' => 0,
                'department_id' => $department->id,
            ]],
        ], $ctx['headers'])->assertStatus(201);

        $this->assertSame([], $res->json('meta.warnings'));
    }

    public function test_posting_without_any_matching_budget_line_returns_no_warning(): void
    {
        $ctx = $this->bootPostingTenant();

        $res = $this->postJson('/api/sales/invoices', [
            'customer_id' => $this->createCustomer(),
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => false,
            'tax_included' => false,
            'lines' => [['description' => 'Jasa renovasi', 'quantity' => 1, 'unit_price' => 1_000, 'tax_rate' => 0]],
        ], $ctx['headers'])->assertStatus(201);

        $this->assertSame([], $res->json('meta.warnings'));
    }

    // ------------------------------------------------------------- helpers

    private function seedPostingMappings(): void
    {
        $mappings = [
            'sales.accounts_receivable' => $this->account('1100', 'Accounts Receivable', 'asset', 'debit'),
            'sales.revenue' => $this->account('4100', 'Sales Revenue', 'revenue', 'credit'),
            'sales.tax_output' => $this->account('2100', 'Output Tax', 'liability', 'credit'),
            'sales.customer_deposit' => $this->account('2200', 'Customer Deposit', 'liability', 'credit'),
            'sales.return' => $this->account('4200', 'Sales Return', 'revenue', 'credit'),
            'sales.discount' => $this->account('4300', 'Sales Discount', 'revenue', 'credit'),
        ];

        foreach ($mappings as $key => $accountId) {
            AccountMapping::query()->updateOrCreate(
                ['mapping_key' => $key],
                ['module' => 'sales', 'account_id' => $accountId, 'is_active' => true],
            );
        }
    }

    private function account(string $code, string $name, string $type, string $normal): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_cash_bank' => false,
            'is_active' => true,
        ])->id;
    }
}
