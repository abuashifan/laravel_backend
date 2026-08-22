<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Models\VendorBillLine;
use Tests\Feature\Purchase\PurchaseTestCase;

class PurchaseByProductReportTest extends PurchaseTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/purchase/by-product')->assertStatus(401);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/purchase/by-product', $ctx['headers'])->assertStatus(403);
    }

    public function test_aggregates_per_product_and_excludes_draft(): void
    {
        $ctx = $this->setUpTenant();
        $vendorId = $this->createVendor();

        $productA = $this->createProduct('PRD-A', 'Product Alpha');
        $productB = $this->createProduct('PRD-B', 'Product Beta');

        // Product A across 2 posted bills, Product B in 1.
        $posted1 = $this->makeBill($vendorId, '2026-06-01', 'posted');
        $this->makeLine($posted1, $productA, 'PRD-A', 2, 100, 200);

        $posted2 = $this->makeBill($vendorId, '2026-06-05', 'posted');
        $this->makeLine($posted2, $productA, 'PRD-A', 3, 100, 300);
        $this->makeLine($posted2, $productB, 'PRD-B', 1, 200, 200);

        // Draft — should NOT count.
        $draft = $this->makeBill($vendorId, '2026-06-20', 'draft');
        $this->makeLine($draft, $productA, 'PRD-A', 99, 1, 99);

        $res = $this->getJson('/api/reports/purchase/by-product?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])
            ->assertStatus(200);

        $rows = collect($res->json('data.rows'));
        $totals = $res->json('data.totals');

        $this->assertCount(2, $rows);

        $rowA = $rows->firstWhere('product_id', $productA);
        $rowB = $rows->firstWhere('product_id', $productB);

        $this->assertNotNull($rowA);
        $this->assertEquals(5.0, $rowA['qty']); // 2 + 3
        $this->assertEquals(500.0, $rowA['total']); // 200 + 300

        $this->assertNotNull($rowB);
        $this->assertEquals(1.0, $rowB['qty']);
        $this->assertEquals(200.0, $rowB['total']);

        $this->assertEquals(6.0, $totals['qty']);
        $this->assertEquals(700.0, $totals['total']);
    }

    public function test_product_id_filter_limits_to_single_product(): void
    {
        $ctx = $this->setUpTenant();
        $vendorId = $this->createVendor();

        $productA = $this->createProduct('PRD-A', 'Alpha');
        $productB = $this->createProduct('PRD-B', 'Beta');

        $bill = $this->makeBill($vendorId, '2026-06-01', 'posted');
        $this->makeLine($bill, $productA, 'PRD-A', 1, 100, 100);
        $this->makeLine($bill, $productB, 'PRD-B', 1, 200, 200);

        $res = $this->getJson("/api/reports/purchase/by-product?product_id={$productA}", $ctx['headers'])
            ->assertStatus(200);

        $rows = $res->json('data.rows');
        $this->assertCount(1, $rows);
        $this->assertEquals($productA, $rows[0]['product_id']);
    }

    private function createProduct(string $code, string $name): int
    {
        $unit = Unit::query()->firstOrCreate(
            ['code' => 'PCS'],
            ['name' => 'Pieces', 'precision' => 0, 'is_active' => true],
        );

        return (int) Product::query()->create([
            'product_code' => $code,
            'product_name' => $name,
            'product_type' => 'service',
            'unit_id' => $unit->id,
            'is_stock_item' => false,
            'is_active' => true,
        ])->id;
    }

    private function makeBill(int $vendorId, string $date, string $status): int
    {
        return (int) VendorBill::query()->create([
            'bill_number' => 'VB-'.uniqid(),
            'bill_date' => $date,
            'vendor_id' => $vendorId,
            'status' => $status,
            'subtotal_after_discount' => 0,
            'tax_total' => 0,
            'grand_total' => 0,
        ])->id;
    }

    private function makeLine(int $billId, int $productId, string $productCode, float $qty, float $unitPrice, float $lineTotal): void
    {
        VendorBillLine::query()->create([
            'vendor_bill_id' => $billId,
            'product_id' => $productId,
            'product_code' => $productCode,
            'description' => $productCode,
            'quantity' => $qty,
            'unit_price' => $unitPrice,
            'subtotal_after_discount' => $lineTotal,
            'line_total' => $lineTotal,
        ]);
    }
}
