<?php

namespace Tests\Feature\Purchase;

use App\Models\Tenant\PurchaseRequest;
use App\Models\Tenant\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PurchaseRequestTest extends PurchaseTestCase
{
    public function test_unauthenticated_rejected(): void
    {
        $this->getJson('/api/purchase/requests')->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant();
        $this->getJson('/api/purchase/requests')->assertStatus(422);
    }

    public function test_can_create_purchase_request(): void
    {
        $ctx = $this->setUpTenant();

        $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.estimated_total', 150000);

        $this->assertDatabaseCount('purchase_requests', 1, 'tenant');
        $this->assertDatabaseCount('purchase_request_lines', 1, 'tenant');
    }

    public function test_can_update_draft_purchase_request(): void
    {
        $ctx = $this->setUpTenant();
        $purchaseRequest = $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->json('data');

        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'], $this->purchaseRequestPayload([
            'lines' => [['description' => 'Updated', 'quantity' => 3, 'estimated_unit_price' => 50000]],
        ]), $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.revision_no', 2)
            ->assertJsonPath('data.estimated_total', 150000);
    }

    public function test_can_submit_purchase_request(): void
    {
        $ctx = $this->setUpTenant();
        $purchaseRequest = $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->json('data');

        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'].'/submit', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'submitted');
    }

    public function test_can_approve_purchase_request(): void
    {
        $ctx = $this->setUpTenant();
        $purchaseRequest = $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->json('data');
        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'].'/submit', [], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'].'/approve', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_can_reject_purchase_request(): void
    {
        $ctx = $this->setUpTenant();
        $purchaseRequest = $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->json('data');
        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'].'/submit', [], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'].'/reject', ['reason' => 'Budget hold'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'rejected');
    }

    public function test_can_cancel_purchase_request(): void
    {
        $ctx = $this->setUpTenant();
        $purchaseRequest = $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->json('data');

        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'].'/cancel', ['reason' => 'Duplicate'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cannot_update_cancelled_purchase_request(): void
    {
        $ctx = $this->setUpTenant();
        $purchaseRequest = $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->json('data');
        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'].'/cancel', [], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/purchase/requests/'.$purchaseRequest['id'], $this->purchaseRequestPayload(), $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_no_journal_and_no_stock_movement_created(): void
    {
        $ctx = $this->setUpTenant();
        $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctx['headers'])->assertStatus(201);

        $this->assertSame(0, DB::connection('tenant')->table('journal_entries')->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    public function test_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $this->postJson('/api/purchase/requests', $this->purchaseRequestPayload(), $ctxA['headers'])->assertStatus(201);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, PurchaseRequest::query()->count());

        $this->getJson('/api/purchase/requests', $ctxB['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant('viewer');

        $this->getJson('/api/purchase/requests', $ctx['headers'])->assertStatus(403);
    }
}
