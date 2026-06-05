<?php

namespace Tests\Feature\Sales;

use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesReturn;
use App\Models\Tenant\StockBalance;
use App\Models\Tenant\StockMovement;

class SalesReturnTest extends SalesTestCase
{
    public function test_create_return_from_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);

        $this->postJson('/api/sales/returns/from-invoice/'.$invoice['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_invoice_id', $invoice['id'])
            ->assertJsonPath('data.grand_total', 100);
    }

    public function test_create_return_from_delivery_order_if_allowed(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->postJson('/api/sales/orders', ['customer_id' => $this->createCustomer(), 'order_date' => '2026-05-20', 'lines' => [['description' => 'Goods', 'quantity' => 1, 'unit_price' => 100]]], $ctx['headers'])->assertStatus(201)->json('data');
        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/sales/returns/from-delivery-order/'.$delivery['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_order_id', $delivery['id']);
    }

    public function test_cannot_return_more_than_invoiced_quantity(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);

        $this->postJson('/api/sales/returns', [
            'return_date' => '2026-05-20',
            'customer_id' => $invoice['customer_id'],
            'sales_invoice_id' => $invoice['id'],
            'lines' => [[
                'sales_invoice_line_id' => $invoice['lines'][0]['id'],
                'description' => 'Service',
                'quantity' => 2,
                'unit_price' => 100,
            ]],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_post_return_creates_contra_revenue_ar_journal_and_updates_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $return = $this->postJson('/api/sales/returns/from-invoice/'.$invoice['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(2, JournalEntry::query()->count());
        $updated = SalesInvoice::query()->find($invoice['id']);
        $this->assertSame(100.0, (float) $updated->returned_amount);
        $this->assertSame(0.0, (float) $updated->balance_due);
    }

    public function test_sales_return_does_not_create_stock_movement_or_update_stock_balance(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $return = $this->postJson('/api/sales/returns/from-invoice/'.$invoice['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, StockBalance::query()->count());
    }

    public function test_void_period_lock_permission_and_tenant_isolation(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $return = $this->postJson('/api/sales/returns/from-invoice/'.$invoice['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/returns/'.$return['id'].'/void', ['reason' => 'Wrong'], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'void');

        FiscalYear::query()->updateOrCreate(
            ['company_id' => $ctx['company']->id, 'year' => 2026],
            ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'closed', 'is_active' => true]
        );
        $locked = $this->postJson('/api/sales/returns/from-invoice/'.$invoice['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/returns/'.$locked['id'].'/post', [], $ctx['headers'])->assertStatus(422);

        $viewer = $this->setUpTenant('viewer');
        $this->postJson('/api/sales/returns', ['return_date' => '2026-05-20', 'customer_id' => 1, 'lines' => [['description' => 'X', 'quantity' => 1, 'unit_price' => 1]]], $viewer['headers'])->assertStatus(403);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, SalesReturn::query()->count());
    }

    private function postedInvoice(array $ctx): array
    {
        $invoice = $this->postJson('/api/sales/invoices', ['customer_id' => $this->createCustomer(), 'invoice_date' => '2026-05-20', 'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]]], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        return SalesInvoice::query()->with('lines')->find($invoice['id'])->toArray();
    }

    private function seedMappings(): void
    {
        $ar = $this->account('1100', 'AR', 'asset', 'debit');
        $revenue = $this->account('4100', 'Revenue', 'revenue', 'credit');
        $salesReturn = $this->account('4200', 'Sales Return', 'revenue', 'debit');
        foreach (['sales.accounts_receivable' => $ar, 'sales.revenue' => $revenue, 'sales.return' => $salesReturn] as $key => $id) AccountMapping::query()->create(['mapping_key' => $key, 'module' => 'sales', 'account_id' => $id, 'is_required' => true, 'is_active' => true]);
    }

    private function account(string $code, string $name, string $type, string $normal): int
    {
        return (int) ChartOfAccount::query()->create(['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_active' => true])->id;
    }
}
