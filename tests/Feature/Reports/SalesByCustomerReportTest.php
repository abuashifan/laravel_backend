<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use Tests\Feature\Sales\SalesTestCase;

class SalesByCustomerReportTest extends SalesTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/sales/by-customer')->assertStatus(401);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/sales/by-customer', $ctx['headers'])->assertStatus(403);
    }

    public function test_aggregates_per_customer_and_excludes_draft(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();

        $customerA = $this->createCustomer(['name' => 'Customer Alpha']);
        $customerB = $this->createCustomer(['name' => 'Customer Beta', 'contact_type' => 'customer', 'is_customer' => true]);

        // 2 invoices for A, 1 for B
        $this->postAndPostInvoice($ctx, $customerA, '2026-06-01', 100);
        $this->postAndPostInvoice($ctx, $customerA, '2026-06-05', 150);
        $this->postAndPostInvoice($ctx, $customerB, '2026-06-10', 200);

        // Draft for A — should NOT count
        $this->postJson('/api/sales/invoices', [
            'customer_id' => $customerA,
            'invoice_date' => '2026-06-20',
            'due_date' => '2026-07-20',
            'lines' => [['description' => 'Draft', 'quantity' => 1, 'unit_price' => 999]],
        ], $ctx['headers'])->assertStatus(201);

        $res = $this->getJson('/api/reports/sales/by-customer?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $rows = collect($res->json('data.rows'));
        $totals = $res->json('data.totals');

        $this->assertCount(2, $rows);
        $rowA = $rows->firstWhere('customer_id', $customerA);
        $rowB = $rows->firstWhere('customer_id', $customerB);

        $this->assertNotNull($rowA);
        $this->assertEquals(2, $rowA['invoice_count']);
        $this->assertEquals(250.0, $rowA['total']);

        $this->assertNotNull($rowB);
        $this->assertEquals(1, $rowB['invoice_count']);
        $this->assertEquals(200.0, $rowB['total']);

        $this->assertEquals(450.0, $totals['total']);
    }

    public function test_customer_id_filter_limits_to_single_customer(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();

        $customerA = $this->createCustomer(['name' => 'Alpha']);
        $customerB = $this->createCustomer(['name' => 'Beta', 'contact_type' => 'customer', 'is_customer' => true]);

        $this->postAndPostInvoice($ctx, $customerA, '2026-06-01', 100);
        $this->postAndPostInvoice($ctx, $customerB, '2026-06-01', 200);

        $res = $this->getJson("/api/reports/sales/by-customer?customer_id={$customerA}", $ctx['headers'])
            ->assertStatus(200);

        $rows = $res->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($customerA, $rows[0]['customer_id']);
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
