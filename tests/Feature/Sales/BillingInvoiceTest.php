<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\BillingInvoice;
use App\Models\Tenant\JournalEntry;

class BillingInvoiceTest extends SalesTestCase
{
    public function test_create_billing_from_sales_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $invoice = $this->createInvoice($ctx);

        $this->postJson('/api/sales/billings/from-sales-invoice/'.$invoice['id'], [], $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_invoice_id', $invoice['id'])
            ->assertJsonPath('data.billing_amount', 222);
    }

    public function test_billing_does_not_create_ar_or_revenue_journal(): void
    {
        $ctx = $this->setUpTenant();
        $invoice = $this->createInvoice($ctx);
        $this->postJson('/api/sales/billings/from-sales-invoice/'.$invoice['id'], [], $ctx['headers'])->assertStatus(201);

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_issue_and_cancel_billing(): void
    {
        $ctx = $this->setUpTenant();
        $invoice = $this->createInvoice($ctx);
        $issued = $this->postJson('/api/sales/billings/from-sales-invoice/'.$invoice['id'], [], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/billings/'.$issued['id'].'/issue', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'issued');

        $cancelled = $this->postJson('/api/sales/billings/from-sales-invoice/'.$invoice['id'], [], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/billings/'.$cancelled['id'].'/cancel', ['reason' => 'Duplicate'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_permission_denied_for_viewer(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->postJson('/api/sales/billings', $this->billingPayload(), $ctx['headers'])
            ->assertStatus(403);
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/sales/billings', $this->billingPayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, BillingInvoice::query()->count());
        $this->getJson('/api/sales/billings', $ctxB['headers'])->assertStatus(200)->assertJsonCount(0, 'data');
    }

    private function createInvoice(array $ctx): array
    {
        return $this->postJson('/api/sales/invoices', [
            'customer_id' => $this->createCustomer(),
            'invoice_date' => '2026-05-20',
            'lines' => [['description' => 'Service', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 11]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
    }

    private function billingPayload(): array
    {
        return [
            'customer_id' => $this->createCustomer(),
            'billing_date' => '2026-05-20',
            'lines' => [['description' => 'Progress billing', 'amount' => 100]],
        ];
    }
}
