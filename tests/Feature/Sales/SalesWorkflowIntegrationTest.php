<?php

namespace Tests\Feature\Sales;

use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\SalesInvoice;
use App\Models\Tenant\SalesOrderLine;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\Route;

class SalesWorkflowIntegrationTest extends SalesTestCase
{
    public function test_full_chain_without_payment_keeps_source_chain_and_posts_invoice_journal(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $quotation = $this->postJson('/api/sales/quotations', $this->quotationPayload(['customer_id' => $this->createCustomer()]), $ctx['headers'])->assertStatus(201)->json('data');
        $order = $this->postJson('/api/sales/orders/from-quotation/'.$quotation['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $proforma = $this->postJson('/api/sales/proformas/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $invoice = $this->postJson('/api/sales/invoices/from-proforma/'.$proforma['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'posted');

        $this->assertSame('sales_quotation', $order['source_type']);
        $this->assertSame($quotation['id'], $order['quotation_id']);
        $this->assertSame($order['id'], $delivery['sales_order_id']);
        $this->assertSame($order['id'], $proforma['sales_order_id']);
        $this->assertSame($proforma['id'], $invoice['proforma_invoice_id']);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'sales_invoice')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_sales_order_down_payment_is_applied_to_invoice_without_new_invoice_dp_input(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $customerId = $this->createCustomer();
        $order = $this->postJson('/api/sales/orders', $this->orderPayload([
            'customer_id' => $customerId,
            'has_down_payment' => true,
            'down_payment' => ['deposit_date' => '2026-05-20', 'cash_bank_account_id' => $cash, 'amount' => 50],
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $deposit = CustomerDeposit::query()->where('sales_order_id', $order['id'])->firstOrFail();

        $this->patchJson('/api/sales/customer-deposits/'.$deposit->id.'/post', [], $ctx['headers'])->assertStatus(200);
        $invoice = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.applied_down_payment_amount', 50)
            ->json('data');
        $posted = $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200)->json('data');

        $this->assertSame(1, CustomerDepositAllocation::query()->count());
        $this->assertSame(3, JournalEntry::query()->count());
        $this->assertSame(172.0, (float) $posted['balance_due']);
    }

    public function test_direct_invoice_and_discount_flow_do_not_create_stock_or_cogs(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $customerId = $this->createCustomer();
        $order = $this->postJson('/api/sales/orders', $this->orderPayload([
            'customer_id' => $customerId,
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 10,
            'lines' => [[
                'description' => 'Discounted service',
                'quantity' => 2,
                'unit_price' => 100,
                'discount_type' => 'percent',
                'discount_value' => 10,
            ]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $invoice = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.header_discount_amount', 10)
            ->json('data');

        $adjusted = $this->patchJson('/api/sales/invoices/'.$invoice['id'], $this->invoicePayload($customerId, [
            'header_discount_type' => 'percent',
            'header_discount_value' => 20,
        ]), $ctx['headers'])->assertStatus(200)->json('data');
        $this->patchJson('/api/sales/invoices/'.$adjusted['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $direct = $this->postJson('/api/sales/invoices', $this->invoicePayload($this->createCustomer()), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$direct['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(2, JournalEntry::query()->where('source_type', 'sales_invoice')->count());
        $this->assertSame(0, StockMovement::query()->count());
        $this->assertSame(0, JournalEntry::query()->where('description', 'like', '%COGS%')->count());
    }

    public function test_delivery_order_only_updates_delivered_quantity_without_stock_movement(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->postJson('/api/sales/orders', $this->orderPayload(['customer_id' => $this->createCustomer()]), $ctx['headers'])->assertStatus(201)->json('data');
        $delivery = $this->postJson('/api/sales/delivery-orders/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/delivery-orders/'.$delivery['id'].'/deliver', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(2.0, (float) SalesOrderLine::query()->where('sales_order_id', $order['id'])->first()->delivered_quantity);
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_ar_ledger_reconciles_after_invoice_dp_receipt_and_return(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $customerId = $this->createCustomer();
        $order = $this->postJson('/api/sales/orders', $this->orderPayload(['customer_id' => $customerId]), $ctx['headers'])->assertStatus(201)->json('data');
        $deposit = CustomerDeposit::query()->create(['deposit_number' => 'CD-INT-1', 'deposit_date' => '2026-05-20', 'customer_id' => $customerId, 'sales_order_id' => $order['id'], 'cash_bank_account_id' => $cash, 'amount' => 50, 'allocated_amount' => 0, 'remaining_amount' => 50, 'status' => 'posted', 'posted_at' => now()]);
        $invoice = $this->postJson('/api/sales/invoices/from-sales-order/'.$order['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $receipt = $this->postJson('/api/sales/receipts', ['receipt_date' => '2026-05-20', 'customer_id' => $customerId, 'sales_invoice_id' => $invoice['id'], 'cash_bank_account_id' => $cash, 'amount' => 50], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $return = $this->postJson('/api/sales/returns', ['return_date' => '2026-05-20', 'customer_id' => $customerId, 'sales_invoice_id' => $invoice['id'], 'lines' => [['sales_invoice_line_id' => SalesInvoice::query()->with('lines')->find($invoice['id'])->lines[0]->id, 'description' => 'Return', 'quantity' => 0.5, 'unit_price' => 100, 'line_total' => 50]]], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(0.0, (float) $deposit->refresh()->remaining_amount);
        $this->getJson('/api/sales/ar/customers/'.$customerId.'/ledger', $ctx['headers'])->assertStatus(200)->assertJsonPath('data.movements.3.balance', 72);
        $this->getJson('/api/sales/ar/reconciliation', $ctx['headers'])->assertStatus(200)->assertJsonPath('data.is_reconciled', true);
    }

    public function test_period_lock_void_dependency_and_tenant_isolation_rules(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postJson('/api/sales/invoices', $this->invoicePayload($this->createCustomer()), $ctx['headers'])->assertStatus(201)->json('data');
        FiscalYear::query()->updateOrCreate(['company_id' => $ctx['company']->id, 'year' => 2026], ['start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'closed', 'is_active' => true]);
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(422);
        FiscalYear::query()->where('company_id', $ctx['company']->id)->update(['status' => 'open']);
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/sales/invoices/'.$invoice['id'], $this->invoicePayload($invoice['customer_id']), $ctx['headers'])->assertStatus(422);
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/void', ['reason' => 'Integration void'], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'void');

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, SalesInvoice::query()->count());
        $this->getJson('/api/sales/invoices/'.$invoice['id'], $ctxB['headers'])->assertStatus(404);
    }

    public function test_sales_routes_are_protected_by_auth_company_and_permissions(): void
    {
        $salesRoutes = collect(Route::getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/sales/'));
        $this->assertGreaterThan(0, $salesRoutes->count());

        foreach ($salesRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:sanctum', $middleware, $route->uri());
            $this->assertContains('company.access', $middleware, $route->uri());
            $this->assertTrue(collect($middleware)->contains(fn ($item) => str_starts_with($item, 'permission:')), $route->uri());
        }
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_replace_recursive(['customer_id' => $this->createCustomer(), 'order_date' => '2026-05-20', 'is_taxable' => true, 'tax_included' => false, 'lines' => [['description' => 'Implementation service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]]], $overrides);
    }

    private function invoicePayload(int $customerId, array $overrides = []): array
    {
        return array_replace_recursive(['customer_id' => $customerId, 'invoice_date' => '2026-05-20', 'due_date' => '2026-05-30', 'is_taxable' => true, 'tax_included' => false, 'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]]], $overrides);
    }

    private function seedMappings(): int
    {
        $cash = $this->account('1000', 'Cash', 'asset', 'debit', true);
        $ar = $this->account('1100', 'AR', 'asset', 'debit');
        $tax = $this->account('2100', 'Output Tax', 'liability', 'credit');
        $deposit = $this->account('2200', 'Customer Deposit', 'liability', 'credit');
        $revenue = $this->account('4100', 'Revenue', 'revenue', 'credit');
        $salesReturn = $this->account('4200', 'Sales Return', 'revenue', 'debit');
        foreach (['sales.accounts_receivable' => $ar, 'sales.revenue' => $revenue, 'sales.tax_output' => $tax, 'sales.customer_deposit' => $deposit, 'sales.return' => $salesReturn] as $key => $id) {
            AccountMapping::query()->create(['mapping_key' => $key, 'module' => 'sales', 'account_id' => $id, 'is_required' => true, 'is_active' => true]);
        }

        return $cash;
    }

    private function account(string $code, string $name, string $type, string $normal, bool $cash = false): int
    {
        return (int) ChartOfAccount::query()->create(['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_cash_bank' => $cash, 'is_active' => true])->id;
    }
}
