<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Product;
use Tests\Feature\Sales\SalesTestCase;

class SalesByProductReportTest extends SalesTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/sales/by-product')->assertStatus(401);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/sales/by-product', $ctx['headers'])->assertStatus(403);
    }

    public function test_aggregates_per_product_and_excludes_draft(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $customerId = $this->createCustomer();

        $productA = $this->createProduct('P-001', 'Widget A');
        $productB = $this->createProduct('P-002', 'Widget B');

        // 2 lines for product A (in different invoices), 1 for B
        $this->postAndPostInvoice($ctx, $customerId, '2026-06-01', $productA, 2, 100);
        $this->postAndPostInvoice($ctx, $customerId, '2026-06-05', $productA, 3, 100);
        $this->postAndPostInvoice($ctx, $customerId, '2026-06-10', $productB, 1, 500);

        // Draft invoice — should NOT count
        $this->postJson('/api/sales/invoices', [
            'customer_id' => $customerId,
            'invoice_date' => '2026-06-20',
            'due_date' => '2026-07-20',
            'lines' => [['description' => 'Draft line', 'product_id' => $productA, 'quantity' => 99, 'unit_price' => 999]],
        ], $ctx['headers'])->assertStatus(201);

        $res = $this->getJson('/api/reports/sales/by-product?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $rows = collect($res->json('data.rows'));
        $totals = $res->json('data.totals');

        $this->assertCount(2, $rows);

        $rowA = $rows->firstWhere('product_id', $productA);
        $rowB = $rows->firstWhere('product_id', $productB);

        $this->assertNotNull($rowA);
        $this->assertEquals(5.0, $rowA['qty']);      // 2 + 3
        $this->assertEquals(500.0, $rowA['total']);  // (2×100) + (3×100)

        $this->assertNotNull($rowB);
        $this->assertEquals(1.0, $rowB['qty']);
        $this->assertEquals(500.0, $rowB['total']);

        $this->assertEquals(6.0, $totals['qty']);
        $this->assertEquals(1000.0, $totals['total']);
    }

    public function test_product_id_filter_limits_to_single_product(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $customerId = $this->createCustomer();

        $productA = $this->createProduct('P-001', 'Widget A');
        $productB = $this->createProduct('P-002', 'Widget B');

        $this->postAndPostInvoice($ctx, $customerId, '2026-06-01', $productA, 1, 100);
        $this->postAndPostInvoice($ctx, $customerId, '2026-06-01', $productB, 1, 200);

        $res = $this->getJson("/api/reports/sales/by-product?product_id={$productA}", $ctx['headers'])
            ->assertStatus(200);

        $rows = $res->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($productA, $rows[0]['product_id']);
    }

    private function postAndPostInvoice(array $ctx, int $customerId, string $date, int $productId, float $qty, float $price): void
    {
        $invoice = $this->postJson('/api/sales/invoices', [
            'customer_id' => $customerId,
            'invoice_date' => $date,
            'due_date' => date('Y-m-d', strtotime($date.' +30 days')),
            'lines' => [[
                'description' => 'Product line',
                'product_id' => $productId,
                'quantity' => $qty,
                'unit_price' => $price,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);
    }

    private function createProduct(string $code, string $name): int
    {
        return (int) Product::query()->create([
            'product_code' => $code,
            'product_name' => $name,
            'is_active' => true,
        ])->id;
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
