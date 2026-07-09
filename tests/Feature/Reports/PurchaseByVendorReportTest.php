<?php

namespace Tests\Feature\Reports;

use App\Modules\Purchase\Models\VendorBill;
use Tests\Feature\Purchase\PurchaseTestCase;

class PurchaseByVendorReportTest extends PurchaseTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/purchase/by-vendor')->assertStatus(401);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/purchase/by-vendor', $ctx['headers'])->assertStatus(403);
    }

    public function test_aggregates_per_vendor_and_excludes_draft(): void
    {
        $ctx = $this->setUpTenant();

        $vendorA = $this->createVendor(['name' => 'Vendor Alpha']);
        $vendorB = $this->createVendor(['name' => 'Vendor Beta']);

        $this->makeBill($vendorA, '2026-06-01', 100, 'posted');
        $this->makeBill($vendorA, '2026-06-05', 150, 'posted');
        $this->makeBill($vendorB, '2026-06-10', 200, 'posted');
        // Draft for A — should NOT count.
        $this->makeBill($vendorA, '2026-06-20', 999, 'draft');

        $res = $this->getJson('/api/reports/purchase/by-vendor?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $rows = collect($res->json('data.rows'));
        $totals = $res->json('data.totals');

        $this->assertCount(2, $rows);

        $rowA = $rows->firstWhere('vendor_id', $vendorA);
        $rowB = $rows->firstWhere('vendor_id', $vendorB);

        $this->assertNotNull($rowA);
        $this->assertEquals(2, $rowA['bill_count']);
        $this->assertEquals(250.0, $rowA['total']);

        $this->assertNotNull($rowB);
        $this->assertEquals(1, $rowB['bill_count']);
        $this->assertEquals(200.0, $rowB['total']);

        $this->assertEquals(450.0, $totals['total']);
    }

    public function test_vendor_id_filter_limits_to_single_vendor(): void
    {
        $ctx = $this->setUpTenant();

        $vendorA = $this->createVendor(['name' => 'Alpha']);
        $vendorB = $this->createVendor(['name' => 'Beta']);

        $this->makeBill($vendorA, '2026-06-01', 100, 'posted');
        $this->makeBill($vendorB, '2026-06-01', 200, 'posted');

        $res = $this->getJson("/api/reports/purchase/by-vendor?vendor_id={$vendorA}", $ctx['headers'])
            ->assertStatus(200);

        $rows = $res->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($vendorA, $rows[0]['vendor_id']);
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
