<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\Contact;
use App\Modules\MasterData\Models\Product;
use App\Modules\MasterData\Models\Unit;
use App\Modules\Purchase\Models\PurchaseReturn;
use App\Modules\Purchase\Models\PurchaseReturnLine;
use App\Modules\Purchase\Models\VendorBill;
use App\Modules\Purchase\Models\VendorBillLine;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesInvoiceLine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use Tests\Feature\Purchase\PurchaseTestCase;

class ProductHistoryReportTest extends PurchaseTestCase
{
    private const URI = '/api/reports/product-history';

    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson(self::URI)->assertStatus(401);
    }

    public function test_user_without_permission_rejected(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson(self::URI, $ctx['headers'])->assertStatus(403);
    }

    public function test_product_id_is_required(): void
    {
        $ctx = $this->setUpTenant();

        $this->getJson(self::URI, $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['product_id']);
    }

    public function test_combines_sales_and_purchase_lines_in_date_order(): void
    {
        $ctx = $this->setUpTenant();
        $vendor = $this->makeContact('PT Sumber Baja', 'supplier');
        $customer = $this->makeContact('Toko Makmur', 'customer');
        $product = $this->makeProduct('PRD-A', 'Semen 50kg');

        // Sengaja dibuat tidak urut supaya pengurutannya benar-benar diuji.
        $this->makeSalesInvoiceLine($customer, '2026-06-09', 'posted', $product, 20, 75000, 1500000);
        $this->makeVendorBillLine($vendor, '2026-06-05', 'posted', $product, 100, 52000, 5200000);
        $this->makePurchaseReturnLine($vendor, '2026-06-20', 'posted', $product, 10, 52000, 520000);

        $rows = $this->getJson(self::URI."?product_id={$product}", $ctx['headers'])
            ->assertStatus(200)
            ->json('data.rows');

        $this->assertSame(['2026-06-05', '2026-06-09', '2026-06-20'], array_column($rows, 'date'));
        $this->assertSame(
            ['vendor_bill', 'sales_invoice', 'purchase_return'],
            array_column($rows, 'document_type'),
        );

        // Kuantitas bertanda mengikuti arah persediaan.
        $this->assertEquals(100.0, $rows[0]['quantity']);
        $this->assertEquals(-20.0, $rows[1]['quantity']);
        $this->assertEquals(-10.0, $rows[2]['quantity']);

        $this->assertSame('PT Sumber Baja', $rows[0]['contact_name']);
        $this->assertSame('Toko Makmur', $rows[1]['contact_name']);
    }

    public function test_returns_reduce_their_own_side_of_totals(): void
    {
        $ctx = $this->setUpTenant();
        $vendor = $this->makeContact('Vendor', 'supplier');
        $customer = $this->makeContact('Customer', 'customer');
        $product = $this->makeProduct('PRD-A', 'Alpha');

        $this->makeVendorBillLine($vendor, '2026-06-01', 'posted', $product, 100, 1000, 100000);
        $this->makePurchaseReturnLine($vendor, '2026-06-02', 'posted', $product, 10, 1000, 10000);
        $this->makeSalesInvoiceLine($customer, '2026-06-03', 'posted', $product, 50, 2000, 100000);
        $this->makeSalesReturnLine($customer, '2026-06-04', 'posted', $product, 5, 2000, 10000);

        $totals = $this->getJson(self::URI."?product_id={$product}", $ctx['headers'])
            ->assertStatus(200)
            ->json('data.totals');

        // Retur pembelian mengurangi total dibeli, bukan menambahnya.
        $this->assertEquals(90.0, $totals['purchased_qty']);
        $this->assertEquals(90000.0, $totals['purchased_value']);
        $this->assertEquals(45.0, $totals['sold_qty']);
        $this->assertEquals(90000.0, $totals['sold_value']);
    }

    /**
     * Rata-rata TERTIMBANG, bukan AVG(unit_price). Dengan 1 @ 100.000 dan
     * 99 @ 50.000, rata-rata polos memberi 75.000 -- angka yang tidak pernah
     * cocok dengan nilai dibagi kuantitas.
     */
    public function test_average_prices_are_weighted(): void
    {
        $ctx = $this->setUpTenant();
        $customer = $this->makeContact('Customer', 'customer');
        $product = $this->makeProduct('PRD-A', 'Alpha');

        $this->makeSalesInvoiceLine($customer, '2026-06-01', 'posted', $product, 1, 100000, 100000);
        $this->makeSalesInvoiceLine($customer, '2026-06-02', 'posted', $product, 99, 50000, 4950000);

        $totals = $this->getJson(self::URI."?product_id={$product}", $ctx['headers'])
            ->assertStatus(200)
            ->json('data.totals');

        $this->assertEquals(100.0, $totals['sold_qty']);
        $this->assertEquals(5050000.0, $totals['sold_value']);
        $this->assertEquals(50500.0, $totals['avg_sell_price']);
        $this->assertNotEquals(75000.0, $totals['avg_sell_price']);
    }

    public function test_excludes_draft_documents(): void
    {
        $ctx = $this->setUpTenant();
        $customer = $this->makeContact('Customer', 'customer');
        $product = $this->makeProduct('PRD-A', 'Alpha');

        $this->makeSalesInvoiceLine($customer, '2026-06-01', 'posted', $product, 5, 1000, 5000);
        $this->makeSalesInvoiceLine($customer, '2026-06-02', 'draft', $product, 999, 1, 999);

        $res = $this->getJson(self::URI."?product_id={$product}", $ctx['headers'])->assertStatus(200);

        $this->assertCount(1, $res->json('data.rows'));
        $this->assertEquals(5.0, $res->json('data.totals.sold_qty'));
    }

    /**
     * Filternya di tabel baris, bukan header -- baris produk lain di dokumen
     * yang sama paling mudah ikut bocor.
     */
    public function test_excludes_other_products_in_the_same_document(): void
    {
        $ctx = $this->setUpTenant();
        $customer = $this->makeContact('Customer', 'customer');
        $productA = $this->makeProduct('PRD-A', 'Alpha');
        $productB = $this->makeProduct('PRD-B', 'Beta');

        $invoiceId = $this->makeSalesInvoice($customer, '2026-06-01', 'posted');
        $this->addSalesInvoiceLine($invoiceId, $productA, 5, 1000, 5000);
        $this->addSalesInvoiceLine($invoiceId, $productB, 7, 2000, 14000);

        $res = $this->getJson(self::URI."?product_id={$productA}", $ctx['headers'])->assertStatus(200);

        $this->assertCount(1, $res->json('data.rows'));
        $this->assertEquals(-5.0, $res->json('data.rows.0.quantity'));
        $this->assertEquals(5000.0, $res->json('data.totals.sold_value'));
    }

    public function test_date_filter_uses_document_date(): void
    {
        $ctx = $this->setUpTenant();
        $customer = $this->makeContact('Customer', 'customer');
        $product = $this->makeProduct('PRD-A', 'Alpha');

        $this->makeSalesInvoiceLine($customer, '2026-05-31', 'posted', $product, 1, 1000, 1000);
        $this->makeSalesInvoiceLine($customer, '2026-06-15', 'posted', $product, 2, 1000, 2000);
        $this->makeSalesInvoiceLine($customer, '2026-07-01', 'posted', $product, 4, 1000, 4000);

        $res = $this->getJson(
            self::URI."?product_id={$product}&start_date=2026-06-01&end_date=2026-06-30",
            $ctx['headers'],
        )->assertStatus(200);

        $this->assertCount(1, $res->json('data.rows'));
        $this->assertSame('2026-06-15', $res->json('data.rows.0.date'));
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function makeContact(string $name, string $type): int
    {
        return (int) Contact::query()->create([
            'name' => $name,
            'contact_type' => $type,
            'is_supplier' => $type === 'supplier',
            'is_customer' => $type === 'customer',
            'is_active' => true,
        ])->id;
    }

    private function makeProduct(string $code, string $name): int
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

    private function makeSalesInvoice(int $customerId, string $date, string $status): int
    {
        return (int) SalesInvoice::query()->create([
            'invoice_number' => 'FJ-'.uniqid(),
            'invoice_date' => $date,
            'customer_id' => $customerId,
            'status' => $status,
        ])->id;
    }

    private function addSalesInvoiceLine(int $invoiceId, int $productId, float $qty, float $price, float $total): void
    {
        SalesInvoiceLine::query()->create([
            'sales_invoice_id' => $invoiceId,
            'product_id' => $productId,
            'description' => 'line',
            'quantity' => $qty,
            'unit_price' => $price,
            'subtotal_after_discount' => $total,
            'line_total' => $total,
        ]);
    }

    private function makeSalesInvoiceLine(int $customerId, string $date, string $status, int $productId, float $qty, float $price, float $total): void
    {
        $this->addSalesInvoiceLine($this->makeSalesInvoice($customerId, $date, $status), $productId, $qty, $price, $total);
    }

    private function makeSalesReturnLine(int $customerId, string $date, string $status, int $productId, float $qty, float $price, float $total): void
    {
        $returnId = (int) SalesReturn::query()->create([
            'return_number' => 'RTJ-'.uniqid(),
            'return_date' => $date,
            'customer_id' => $customerId,
            'status' => $status,
        ])->id;

        SalesReturnLine::query()->create([
            'sales_return_id' => $returnId,
            'product_id' => $productId,
            'description' => 'line',
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $total,
        ]);
    }

    private function makeVendorBillLine(int $vendorId, string $date, string $status, int $productId, float $qty, float $price, float $total): void
    {
        $billId = (int) VendorBill::query()->create([
            'bill_number' => 'TB-'.uniqid(),
            'bill_date' => $date,
            'vendor_id' => $vendorId,
            'status' => $status,
        ])->id;

        VendorBillLine::query()->create([
            'vendor_bill_id' => $billId,
            'product_id' => $productId,
            'description' => 'line',
            'quantity' => $qty,
            'unit_price' => $price,
            'subtotal_after_discount' => $total,
            'line_total' => $total,
        ]);
    }

    private function makePurchaseReturnLine(int $vendorId, string $date, string $status, int $productId, float $qty, float $price, float $total): void
    {
        $returnId = (int) PurchaseReturn::query()->create([
            'return_number' => 'RTB-'.uniqid(),
            'return_date' => $date,
            'vendor_id' => $vendorId,
            'status' => $status,
        ])->id;

        PurchaseReturnLine::query()->create([
            'purchase_return_id' => $returnId,
            'product_id' => $productId,
            'description' => 'line',
            'quantity' => $qty,
            'unit_price' => $price,
            'line_total' => $total,
        ]);
    }
}
