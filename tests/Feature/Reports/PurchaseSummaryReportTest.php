<?php

namespace Tests\Feature\Reports;

use App\Modules\Purchase\Models\VendorBill;
use Tests\Feature\Purchase\PurchaseTestCase;

class PurchaseSummaryReportTest extends PurchaseTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/purchase/summary')->assertStatus(401);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/purchase/summary', $ctx['headers'])->assertStatus(403);
    }

    public function test_invalid_dates_return_422(): void
    {
        $ctx = $this->setUpTenant();
        $this->getJson('/api/reports/purchase/summary?start_date=not-a-date', $ctx['headers'])
            ->assertStatus(422);
    }

    public function test_summary_aggregates_posted_and_excludes_draft(): void
    {
        $ctx = $this->setUpTenant();
        $vendorId = $this->createVendor();

        $this->makeBill($vendorId, '2026-06-01', 100, 'posted');
        $this->makeBill($vendorId, '2026-06-05', 150, 'posted');
        // Draft — should NOT count.
        $this->makeBill($vendorId, '2026-06-20', 999, 'draft');

        $res = $this->getJson('/api/reports/purchase/summary?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $totals = $res->json('data.totals');
        $this->assertEquals(2, $totals['bill_count']);
        $this->assertEquals(250.0, $totals['total']);
    }

    public function test_group_by_day_param_is_accepted(): void
    {
        $ctx = $this->setUpTenant();
        $vendorId = $this->createVendor();

        $this->makeBill($vendorId, '2026-06-01', 100, 'posted');

        $res = $this->getJson('/api/reports/purchase/summary?start_date=2026-06-01&end_date=2026-06-30&group_by=day', $ctx['headers'])
            ->assertStatus(200);

        $this->assertEquals(1, $res->json('data.totals.bill_count'));
        $this->assertEquals(100.0, $res->json('data.totals.total'));
    }

    public function test_empty_period_returns_empty_rows(): void
    {
        $ctx = $this->setUpTenant();

        $res = $this->getJson('/api/reports/purchase/summary?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame([], $res->json('data.rows'));
        $this->assertEquals(0, $res->json('data.totals.total'));
    }

    private function makeBill(int $vendorId, string $date, float $amount, string $status): void
    {
        VendorBill::query()->create([
            'bill_number' => 'VB-'.uniqid(),
            'bill_date' => $date,
            'vendor_id' => $vendorId,
            'status' => $status,
            'subtotal_after_discount' => $amount,
            'tax_total' => 0,
            'grand_total' => $amount,
        ]);
    }
}
