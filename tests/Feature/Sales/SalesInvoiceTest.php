<?php

namespace Tests\Feature\Sales;

use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\DeliveryOrderLine;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesInvoiceTest extends SalesTestCase
{
    public function test_create_invoice_directly(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.grand_total', 222);
    }

    public function test_create_invoice_from_sales_order(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx);

        $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_order_id', $order['id'])
            ->assertJsonPath('data.source_type', 'sales_order')
            ->assertJsonPath('data.source_number', $order['order_number'])
            ->assertJsonPath('data.lines.0.source_line_type', 'sales_order_line')
            ->assertJsonPath('data.lines.0.source_line_id', $order['lines'][0]['id']);
    }

    public function test_create_invoice_from_delivery_order(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx);
        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/sales/invoices/from-delivery-order/'.$delivery['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.delivery_order_id', $delivery['id'])
            ->assertJsonPath('data.source_type', 'delivery_order')
            ->assertJsonPath('data.lines.0.source_line_type', 'delivery_order_line')
            ->assertJsonPath('data.lines.0.source_line_id', $delivery['lines'][0]['id'])
            ->assertJsonPath('data.grand_total', 222);
    }

    public function test_invoice_from_sales_order_uses_remaining_quantity_and_updates_status_when_posted(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $order = $this->createSalesOrder($ctx, [
            'is_taxable' => false,
            'lines' => [['description' => 'Service', 'quantity' => 5, 'unit_price' => 100]],
        ]);
        $first = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [
            'lines' => [['sales_order_line_id' => $order['lines'][0]['id'], 'quantity' => 2]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$first['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(2.0, (float) SalesOrderLine::query()->findOrFail($order['lines'][0]['id'])->invoiced_quantity);
        $this->assertSame('partially_invoiced', SalesOrder::query()->findOrFail($order['id'])->status);
        $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.lines.0.quantity', 3);
        $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [
            'lines' => [['sales_order_line_id' => $order['lines'][0]['id'], 'quantity' => 4]],
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_invoice_from_delivery_order_uses_delivered_remaining_and_resolves_order_price(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $order = $this->createSalesOrder($ctx, [
            'is_taxable' => false,
            'lines' => [['description' => 'Goods', 'quantity' => 4, 'unit_price' => 125, 'discount_type' => 'fixed_amount', 'discount_value' => 5]],
        ]);
        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])->assertStatus(200);
        $first = $this->postJson('/api/sales/invoices/from-delivery-order/'.$delivery['id'], [
            'lines' => [['delivery_order_line_id' => $delivery['lines'][0]['id'], 'quantity' => 1]],
        ], $ctx['headers'])->assertStatus(201)->assertJsonPath('data.lines.0.unit_price', 125)->json('data');
        $this->patchJson('/api/sales/invoices/'.$first['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(1.0, (float) DeliveryOrderLine::query()->findOrFail($delivery['lines'][0]['id'])->invoiced_quantity);
        $this->postJson('/api/sales/invoices/from-delivery-order/'.$delivery['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.lines.0.quantity', 3)
            ->assertJsonPath('data.lines.0.unit_price', 125);
    }

    public function test_create_invoice_from_proforma(): void
    {
        $ctx = $this->setUpTenant();
        $proforma = $this->postJson('/api/sales/proformas', $this->proformaPayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/sales/invoices/from-proforma/'.$proforma['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.proforma_invoice_id', $proforma['id'])
            ->assertJsonPath('data.source_type', 'proforma_invoice');
    }

    public function test_copy_sales_order_discount_into_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx, [
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 25,
        ]);

        $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.header_discount_amount', 25);
    }

    public function test_allow_invoice_discount_final_adjustment_before_post(): void
    {
        $ctx = $this->setUpTenant();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'], $this->invoicePayload([
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.header_discount_amount', 20);
    }

    public function test_line_and_header_discount_calculations(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [['description' => 'Line', 'quantity' => 2, 'unit_price' => 100, 'discount_type' => 'percent', 'discount_value' => 10]],
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.line_discount_total', 20);

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'lines' => [['description' => 'Line', 'quantity' => 2, 'unit_price' => 100, 'discount_type' => 'fixed_amount', 'discount_value' => 25]],
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.line_discount_total', 25);

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.header_discount_amount', 20);

        $this->postJson('/api/sales/invoices', $this->invoicePayload([
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 40,
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.header_discount_amount', 40);
    }

    public function test_invoice_from_so_reads_and_applies_available_down_payment(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx);
        $this->createPostedDeposit($order['id'], $order['customer_id'], 50);

        $invoice = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.applied_down_payment_amount', 50)
            ->json('data');

        $this->seedMappings();
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'partially_paid')
            ->assertJsonPath('data.paid_amount', 50);

        $this->assertSame(1, CustomerDepositAllocation::query()->count());
    }

    public function test_post_invoice_creates_ar_revenue_journal(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, JournalEntry::query()->count());
        $this->assertSame(3, DB::connection('tenant')->table('journal_entry_lines')->count());

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Posting corrected'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');

        $this->assertSame('void', JournalEntry::query()->firstOrFail()->status);
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Again'], $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_post_invoice_creates_deposit_allocation_journal_if_dp_applied(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $order = $this->createSalesOrder($ctx);
        $this->createPostedDeposit($order['id'], $order['customer_id'], 222);
        $invoice = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.balance_due', 0);

        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame(1, CustomerDepositAllocation::query()->count());
        $this->assertSame(0.0, (float) CustomerDeposit::query()->first()->remaining_amount);

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Reverse deposit allocation'], $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame('void', CustomerDepositAllocation::query()->firstOrFail()->status);
        $this->assertSame(222.0, (float) CustomerDeposit::query()->firstOrFail()->remaining_amount);
        $this->assertSame(0, JournalEntry::query()->where('status', 'posted')->count());
    }

    public function test_invoice_does_not_create_stock_movement_or_cogs_journal(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'sales_invoice')->count());
    }

    public function test_void_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Wrong invoice'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');
    }

    public function test_period_lock_blocks_post(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        FiscalYear::query()->create([
            'company_id' => $ctx['company']->id,
            'year' => 2026,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'status' => 'closed',
            'is_active' => true,
        ]);
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/sales/invoices', $this->invoicePayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, SalesInvoice::query()->count());
        $this->getJson('/api/sales/invoices', $ctxB['headers'])->assertStatus(200)->assertJsonCount(0, 'data');
    }

    private function invoicePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => true,
            'tax_included' => false,
            'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]],
        ], $overrides);
    }

    private function proformaPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'proforma_date' => '2026-05-20',
            'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]],
        ], $overrides);
    }

    private function createSalesOrder(array $ctx, array $overrides = []): array
    {
        return $this->postJson('/api/sales/orders', array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'order_date' => '2026-05-20',
            'is_taxable' => true,
            'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]],
        ], $overrides), $ctx['headers'])->assertStatus(201)->json('data');
    }

    private function createPostedDeposit(int $salesOrderId, int $customerId, float $amount): void
    {
        CustomerDeposit::query()->create([
            'deposit_number' => 'CD-TEST-'.$salesOrderId.'-'.$amount,
            'deposit_date' => '2026-05-20',
            'customer_id' => $customerId,
            'sales_order_id' => $salesOrderId,
            'cash_bank_account_id' => 1,
            'amount' => $amount,
            'allocated_amount' => 0,
            'remaining_amount' => $amount,
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    private function seedMappings(): void
    {
        $ar = $this->account('1100', 'Accounts Receivable', 'asset', 'debit');
        $revenue = $this->account('4100', 'Sales Revenue', 'revenue', 'credit');
        $tax = $this->account('2100', 'Output Tax', 'liability', 'credit');
        $deposit = $this->account('2200', 'Customer Deposit', 'liability', 'credit');

        foreach ([
            'sales.accounts_receivable' => $ar,
            'sales.revenue' => $revenue,
            'sales.tax_output' => $tax,
            'sales.customer_deposit' => $deposit,
        ] as $key => $accountId) {
            AccountMapping::query()->create([
                'mapping_key' => $key,
                'module' => 'sales',
                'account_id' => $accountId,
                'is_required' => true,
                'is_active' => true,
            ]);
        }
    }

    private function account(string $code, string $name, string $type, string $normal): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => $code,
            'account_name' => $name,
            'account_type' => $type,
            'normal_balance' => $normal,
            'is_active' => true,
        ])->id;
    }
}
