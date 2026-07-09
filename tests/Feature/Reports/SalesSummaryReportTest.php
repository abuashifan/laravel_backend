<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use Tests\Feature\Sales\SalesTestCase;

class SalesSummaryReportTest extends SalesTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/sales/summary')->assertStatus(401);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/sales/summary', $ctx['headers'])->assertStatus(403);
    }

    public function test_invalid_dates_return_422(): void
    {
        $ctx = $this->setUpTenant();
        $this->getJson('/api/reports/sales/summary?start_date=2026-06-30&end_date=2026-06-01', $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_summary_aggregates_posted_invoices_and_excludes_draft(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $customerId = $this->createCustomer();

        // Post 2 invoices
        $this->postAndPostInvoice($ctx, $customerId, '2026-06-01', 100);
        $this->postAndPostInvoice($ctx, $customerId, '2026-06-15', 200);

        // Draft invoice — should NOT appear in aggregation
        $this->postJson('/api/sales/invoices', [
            'customer_id' => $customerId,
            'invoice_date' => '2026-06-20',
            'due_date' => '2026-07-20',
            'lines' => [['description' => 'Draft', 'quantity' => 1, 'unit_price' => 999]],
        ], $ctx['headers'])->assertStatus(201);

        $res = $this->getJson('/api/reports/sales/summary?start_date=2026-06-01&end_date=2026-06-30&group_by=month', $ctx['headers'])
            ->assertStatus(200);

        $rows = $res->json('data.rows');
        $totals = $res->json('data.totals');

        $this->assertCount(1, $rows);
        $this->assertEquals('2026-06', $rows[0]['period']);
        $this->assertEquals(2, $rows[0]['invoice_count']);
        $this->assertEquals(300.0, $rows[0]['total']);
        $this->assertEquals(2, $totals['invoice_count']);
        $this->assertEquals(300.0, $totals['total']);
    }

    public function test_group_by_day_param_is_accepted(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $customerId = $this->createCustomer();

        $this->postAndPostInvoice($ctx, $customerId, '2026-06-01', 100);

        $this->getJson('/api/reports/sales/summary?start_date=2026-06-01&end_date=2026-06-30&group_by=day', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['rows', 'totals']]);
    }

    public function test_empty_period_returns_empty_rows(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();

        $res = $this->getJson('/api/reports/sales/summary?start_date=2025-01-01&end_date=2025-12-31', $ctx['headers'])
            ->assertStatus(200);

        $this->assertCount(0, $res->json('data.rows'));
        $this->assertEquals(0, $res->json('data.totals.total'));
    }

    private function postAndPostInvoice(array $ctx, int $customerId, string $date, float $amount): void
    {
        $invoice = $this->postJson('/api/sales/invoices', [
            'customer_id' => $customerId,
            'invoice_date' => $date,
            'due_date' => date('Y-m-d', strtotime($date.' +30 days')),
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);
    }

    private function seedMappings(): void
    {
        $ar = $this->accountId('1100', 'AR', 'asset', 'debit');
        $revenue = $this->accountId('4100', 'Revenue', 'revenue', 'credit');
        $this->accountId('1000', 'Cash', 'asset', 'debit', true);

        foreach (['sales.accounts_receivable' => $ar, 'sales.revenue' => $revenue] as $key => $id) {
            AccountMapping::query()->create(['mapping_key' => $key, 'module' => 'sales', 'account_id' => $id, 'is_required' => true, 'is_active' => true]);
        }
    }

    private function accountId(string $code, string $name, string $type, string $normal, bool $cash = false): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_cash_bank' => $cash,
            'is_active' => true,
        ])->id;
    }
}
