<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\SalesOrder;
use App\Models\Tenant\SalesQuotation;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesOrderTest extends SalesTestCase
{
    public function test_can_create_sales_order_directly(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/orders', $this->orderPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.grand_total', 222);

        $this->assertDatabaseCount('sales_orders', 1, 'tenant');
        $this->assertDatabaseCount('sales_order_lines', 1, 'tenant');
    }

    public function test_can_create_sales_order_from_quotation(): void
    {
        $ctx = $this->setUpTenant();
        $quotation = $this->postJson('/api/sales/quotations', $this->quotationPayload([
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/sales/orders/from-quotation/'.$quotation['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.quotation_id', $quotation['id'])
            ->assertJsonPath('data.header_discount_type', 'percent')
            ->assertJsonPath('data.header_discount_amount', 20);

        $this->assertSame('converted', SalesQuotation::query()->find($quotation['id'])->status);
    }

    public function test_copies_quotation_lines_and_discounts(): void
    {
        $ctx = $this->setUpTenant();
        $quotation = $this->postJson('/api/sales/quotations', $this->quotationPayload([
            'lines' => [[
                'description' => 'Discounted line',
                'quantity' => 2,
                'unit_price' => 100,
                'discount_type' => 'fixed_amount',
                'discount_value' => 30,
            ]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $order = $this->postJson('/api/sales/orders/from-quotation/'.$quotation['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->json('data');

        $this->assertSame(30, (int) $order['lines'][0]['discount_amount']);
        $this->assertNotNull($order['lines'][0]['quotation_line_id']);
    }

    public function test_quotation_id_nullable_and_down_payment_false_works(): void
    {
        $ctx = $this->setUpTenant();

        $order = $this->postJson('/api/sales/orders', $this->orderPayload([
            'quotation_id' => null,
            'has_down_payment' => false,
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->assertNull($order['quotation_id']);
        $this->assertFalse((bool) $order['has_down_payment']);
    }

    public function test_has_down_payment_true_without_deposit_payload_works(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/orders', $this->orderPayload([
            'has_down_payment' => true,
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.has_down_payment', true);

        $this->assertDatabaseCount('customer_deposits', 0, 'tenant');
    }

    public function test_has_down_payment_true_with_deposit_payload_creates_customer_deposit(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->cashBankAccount();

        $order = $this->postJson('/api/sales/orders', $this->orderPayload([
            'has_down_payment' => true,
            'down_payment' => [
                'deposit_date' => '2026-05-20',
                'cash_bank_account_id' => $cash,
                'amount' => 50,
                'notes' => 'DP awal',
            ],
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->assertDatabaseHas('customer_deposits', [
            'sales_order_id' => $order['id'],
            'customer_id' => $order['customer_id'],
            'status' => 'draft',
        ], 'tenant');
    }

    public function test_customer_deposit_does_not_live_as_direct_sales_order_payment_field(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->cashBankAccount();
        $this->postJson('/api/sales/orders', $this->orderPayload([
            'has_down_payment' => true,
            'down_payment' => [
                'deposit_date' => '2026-05-20',
                'cash_bank_account_id' => $cash,
                'amount' => 50,
            ],
        ]), $ctx['headers'])->assertStatus(201);

        $this->assertTrue(Schema::connection('tenant')->hasTable('customer_deposits'));
        $this->assertFalse(Schema::connection('tenant')->hasColumn('sales_orders', 'down_payment_amount'));
    }

    private function cashBankAccount(): int
    {
        return (int) ChartOfAccount::query()->create([
            'account_code' => 'CASH-'.uniqid(),
            'account_name' => 'Cash',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => true,
            'is_active' => true,
        ])->id;
    }

    public function test_sales_order_does_not_create_ar_journal_or_stock_movement(): void
    {
        $ctx = $this->setUpTenant();
        $this->postJson('/api/sales/orders', $this->orderPayload(), $ctx['headers'])->assertStatus(201);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_confirm_and_cancel_sales_order(): void
    {
        $ctx = $this->setUpTenant();

        $confirmed = $this->postJson('/api/sales/orders', $this->orderPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/orders/'.$confirmed['id'].'/confirm', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'confirmed');

        $cancelled = $this->postJson('/api/sales/orders', $this->orderPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/orders/'.$cancelled['id'].'/cancel', ['reason' => 'Customer cancelled'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/sales/orders', $this->orderPayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, SalesOrder::query()->count());
        $this->assertSame(0, CustomerDeposit::query()->count());

        $this->getJson('/api/sales/orders', $ctxB['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/sales/orders', $this->orderPayload(), $ctx['headers'])
            ->assertStatus(403);
    }

    private function orderPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'order_date' => '2026-05-20',
            'has_down_payment' => false,
            'is_taxable' => true,
            'tax_included' => false,
            'lines' => [
                [
                    'description' => 'Implementation service',
                    'quantity' => 2,
                    'unit_price' => 100,
                    'tax_rate' => 11,
                ],
            ],
        ], $overrides);
    }
}
