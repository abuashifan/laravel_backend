<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\SalesQuotation;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SalesQuotationTest extends SalesTestCase
{
    public function test_unauthenticated_rejected(): void
    {
        $this->getJson('/api/sales/quotations')->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant();
        $this->getJson('/api/sales/quotations')->assertStatus(422);
    }

    public function test_can_create_quotation(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/quotations', $this->quotationPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.grand_total', 222);

        $this->assertDatabaseCount('sales_quotations', 1, 'tenant');
        $this->assertDatabaseCount('sales_quotation_lines', 1, 'tenant');
    }

    public function test_can_update_draft_quotation(): void
    {
        $ctx = $this->setUpTenant();
        $quotation = $this->postJson('/api/sales/quotations', $this->quotationPayload(), $ctx['headers'])->json('data');

        $this->patchJson('/api/sales/quotations/'.$quotation['id'], $this->quotationPayload([
            'lines' => [['description' => 'Updated', 'quantity' => 1, 'unit_price' => 50]],
        ]), $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.revision_no', 2)
            ->assertJsonPath('data.grand_total', 55.5);
    }

    public function test_line_discount_percent_calculation(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/quotations', $this->quotationPayload([
            'is_taxable' => false,
            'lines' => [['description' => 'Line', 'quantity' => 2, 'unit_price' => 100, 'discount_type' => 'percent', 'discount_value' => 10]],
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.line_discount_total', 20);
    }

    public function test_line_discount_fixed_calculation(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/quotations', $this->quotationPayload([
            'is_taxable' => false,
            'lines' => [['description' => 'Line', 'quantity' => 2, 'unit_price' => 100, 'discount_type' => 'fixed_amount', 'discount_value' => 25]],
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.line_discount_total', 25);
    }

    public function test_header_discount_calculation(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/sales/quotations', $this->quotationPayload([
            'is_taxable' => false,
            'header_discount_type' => 'percent',
            'header_discount_value' => 10,
        ]), $ctx['headers'])->assertStatus(201)->assertJsonPath('data.header_discount_amount', 20);
    }

    public function test_send_approve_accept_reject_cancel_quotation(): void
    {
        $ctx = $this->setUpTenant();

        $quotation = $this->postJson('/api/sales/quotations', $this->quotationPayload(), $ctx['headers'])->json('data');
        $this->patchJson('/api/sales/quotations/'.$quotation['id'].'/send', [], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'sent');
        $this->patchJson('/api/sales/quotations/'.$quotation['id'].'/approve', [], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'approved');
        $this->patchJson('/api/sales/quotations/'.$quotation['id'].'/accept', [], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'accepted');

        $rejected = $this->postJson('/api/sales/quotations', $this->quotationPayload(), $ctx['headers'])->json('data');
        $this->patchJson('/api/sales/quotations/'.$rejected['id'].'/send', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/sales/quotations/'.$rejected['id'].'/reject', ['reason' => 'No budget'], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'rejected');

        $cancelled = $this->postJson('/api/sales/quotations', $this->quotationPayload(), $ctx['headers'])->json('data');
        $this->patchJson('/api/sales/quotations/'.$cancelled['id'].'/cancel', ['reason' => 'Duplicate'], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cannot_update_cancelled_quotation(): void
    {
        $ctx = $this->setUpTenant();
        $quotation = $this->postJson('/api/sales/quotations', $this->quotationPayload(), $ctx['headers'])->json('data');
        $this->patchJson('/api/sales/quotations/'.$quotation['id'].'/cancel', [], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/sales/quotations/'.$quotation['id'], $this->quotationPayload(), $ctx['headers'])->assertStatus(422);
    }

    public function test_no_journal_and_no_stock_movement_created(): void
    {
        $ctx = $this->setUpTenant();
        $this->postJson('/api/sales/quotations', $this->quotationPayload(), $ctx['headers'])->assertStatus(201);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/sales/quotations', $this->quotationPayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, SalesQuotation::query()->count());

        $this->getJson('/api/sales/quotations', $ctxB['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
