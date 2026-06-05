<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProformaInvoiceTest extends SalesTestCase
{
    public function test_create_proforma_directly(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/proformas', $this->proformaPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.grand_total', 222);

        $this->assertDatabaseCount('proforma_invoices', 1, 'tenant');
        $this->assertDatabaseCount('proforma_invoice_lines', 1, 'tenant');
    }

    public function test_create_from_quotation_is_not_supported(): void
    {
        $ctx = $this->setUpTenant();
        $quotation = $this->postJson('/api/sales/quotations', $this->quotationPayload([
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])->assertStatus(201)->json('data');

        $this->postJson('/api/sales/proformas/from-quotation/'.$quotation['id'], [], $ctx['headers'])
            ->assertStatus(404);
    }

    public function test_create_from_sales_order(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx, [
            'header_discount_type' => 'fixed_amount',
            'header_discount_value' => 25,
        ]);

        $this->postJson('/api/sales/proformas/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_order_id', $order['id'])
            ->assertJsonPath('data.source_type', 'sales_order')
            ->assertJsonPath('data.header_discount_amount', 25);
    }

    public function test_copy_discount_and_tax_preview(): void
    {
        $ctx = $this->setUpTenant();
        $order = $this->createSalesOrder($ctx, [
            'is_taxable' => true,
            'lines' => [[
                'description' => 'Taxed',
                'quantity' => 1,
                'unit_price' => 100,
                'discount_type' => 'percent',
                'discount_value' => 10,
                'tax_rate' => 11,
            ]],
        ]);

        $this->postJson('/api/sales/proformas/from-sales-order/'.$order['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.line_discount_total', 10)
            ->assertJsonPath('data.tax_total', 9.9);
    }

    public function test_delivery_order_is_not_supported_as_proforma_source(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/proformas', $this->proformaPayload([
            'source_type' => 'delivery_order',
            'source_id' => 1,
        ]), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('source_type');
    }

    public function test_issue_accept_and_cancel_proforma(): void
    {
        $ctx = $this->setUpTenant();

        $accepted = $this->postJson('/api/sales/proformas', $this->proformaPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/proformas/'.$accepted['id'].'/issue', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'issued');
        $this->patchJson('/api/sales/proformas/'.$accepted['id'].'/accept', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'accepted');

        $cancelled = $this->postJson('/api/sales/proformas', $this->proformaPayload(), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/proformas/'.$cancelled['id'].'/cancel', ['reason' => 'Not needed'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_no_ar_journal_and_no_stock_movement_created(): void
    {
        $ctx = $this->setUpTenant();
        $this->postJson('/api/sales/proformas', $this->proformaPayload(), $ctx['headers'])->assertStatus(201);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/sales/proformas', $this->proformaPayload(), $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/sales/proformas', $this->proformaPayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->getJson('/api/sales/proformas', $ctxB['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    private function createSalesOrder(array $ctx, array $overrides = []): array
    {
        return $this->postJson('/api/sales/orders', array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'order_date' => '2026-05-20',
            'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]],
        ], $overrides), $ctx['headers'])->assertStatus(201)->json('data');
    }

    private function proformaPayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $this->createCustomer(),
            'proforma_date' => '2026-05-20',
            'valid_until' => '2026-05-30',
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
