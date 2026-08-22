<?php

namespace Tests\Feature\Purchase;

use App\Modules\Budget\Models\BudgetLine;
use App\Modules\Budget\Models\BudgetPeriod;
use App\Modules\Budget\Models\BudgetSubmission;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Department;

/**
 * Gap B — Purchase Order disambungkan ke `BudgetWarningService` sebagai
 * pemeriksaan KOMITMEN, bukan realisasi. PO tidak pernah memposting jurnal
 * (murni dokumen komitmen — lihat `PurchaseOrderController::approve()`), jadi
 * `amountToPost` di sini TETAP nilai baris itu sendiri (bukan `postHoc`,
 * berbeda dari empat integrasi lain) — `actual` yang dibaca `check()` belum
 * memuat apa pun dari PO ini, karena memang belum ada jurnalnya sama sekali.
 */
class PurchaseOrderBudgetWarningTest extends PurchaseTestCase
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

    public function test_approving_a_purchase_order_that_exceeds_the_budget_returns_a_warning(): void
    {
        $ctx = $this->setUpTenant();
        $department = Department::query()->create(['code' => 'OPS', 'name' => 'Operational', 'is_active' => true]);
        $expenseAccount = ChartOfAccount::query()->create([
            'account_code' => '5100',
            'account_name' => 'Office Supplies Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
        ]);

        $this->budgetLineFor($ctx, $expenseAccount, $department, 1_000);

        $order = $this->postJson('/api/purchase/orders', [
            'vendor_id' => $this->createVendor(),
            'order_date' => '2026-01-15',
            'has_down_payment' => false,
            'is_taxable' => false,
            'tax_included' => false,
            'lines' => [[
                'description' => 'Jasa konsultasi',
                'quantity' => 1,
                'unit_price' => 1_500,
                'department_id' => $department->id,
                'expense_account_id' => $expenseAccount->id,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $res = $this->patchJson('/api/purchase/orders/'.$order['id'].'/approve', [], $ctx['headers'])
            ->assertStatus(200);

        $warnings = $res->json('meta.warnings');

        $this->assertCount(1, $warnings);
        $this->assertSame($expenseAccount->id, $warnings[0]['account_id']);
        // Bukan postHoc — PO tidak pernah posting jurnal, jadi actual = 0
        // sebelum ini, dan new_total = amountToPost persis nilai PO-nya sendiri.
        $this->assertEqualsWithDelta(1_500.0, $warnings[0]['new_total'], 0.01);
        $this->assertSame('over_budget', $warnings[0]['state']);
    }

    public function test_purchase_order_lines_without_an_expense_account_are_skipped(): void
    {
        $ctx = $this->setUpTenant();

        // Baris stok biasa (produk + gudang) — tidak punya expense_account_id,
        // jadi tidak boleh menghasilkan peringatan apa pun (akun tujuannya
        // Inventory/aset, bukan laba-rugi).
        $res = $this->postJson('/api/purchase/orders', $this->purchaseOrderPayload(), $ctx['headers'])
            ->assertStatus(201);
        $order = $res->json('data');

        $res = $this->patchJson('/api/purchase/orders/'.$order['id'].'/approve', [], $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame([], $res->json('meta.warnings'));
    }
}
