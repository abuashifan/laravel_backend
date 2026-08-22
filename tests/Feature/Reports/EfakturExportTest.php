<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\Contact;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

class EfakturExportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/tax/efaktur/sales')->assertStatus(401);
        $this->getJson('/api/reports/tax/efaktur/purchase')->assertStatus(401);
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/tax/efaktur/sales', $ctx['headers'])->assertStatus(403);
        $this->getJson('/api/reports/tax/efaktur/purchase', $ctx['headers'])->assertStatus(403);
    }

    public function test_invalid_date_returns_422(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $this->getJson('/api/reports/tax/efaktur/sales?start_date=not-a-date', $ctx['headers'])->assertStatus(422);
    }

    public function test_sales_export_streams_djp_csv_with_derived_masa_pajak(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $customer = Contact::query()->create(['contact_code' => 'C1', 'name' => 'PT Pelanggan', 'contact_type' => 'company', 'is_customer' => true, 'is_supplier' => false, 'is_employee' => false, 'is_active' => true, 'tax_number' => '01.234.567.8-901.000'])->id;

        // Included: posted + paid, taxable, in period.
        $this->insertInvoice('INV-1', '2026-06-05', $customer, 'posted', true, 1000000, 110000);
        $this->insertInvoice('INV-2', '2026-06-20', $customer, 'paid', true, 2000000, 220000);
        // Excluded: non-taxable, draft, out-of-period.
        $this->insertInvoice('INV-NT', '2026-06-10', $customer, 'posted', false, 5000000, 0);
        $this->insertInvoice('INV-DRF', '2026-06-11', $customer, 'draft', true, 9000000, 990000);
        $this->insertInvoice('INV-OLD', '2026-05-30', $customer, 'posted', true, 3000000, 330000);

        $res = $this->get('/api/reports/tax/efaktur/sales?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers']);
        $res->assertStatus(200);
        $res->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('efaktur-keluaran-20260601-20260630.csv', (string) $res->headers->get('content-disposition'));

        $lines = $this->csvLines($res->streamedContent());

        $this->assertSame(['NPWP', 'NAMA', 'NOMOR_FAKTUR_PAJAK', 'TANGGAL_FAKTUR', 'MASA_PAJAK', 'TAHUN_PAJAK', 'DPP', 'PPN', 'TOTAL', 'REFERENSI'], $lines[0]);
        $this->assertCount(3, $lines); // header + 2 rows

        // INV-1: NPWP from contact, NSFP blank, masa 06, tahun 2026, ref = invoice number.
        $this->assertSame('01.234.567.8-901.000', $lines[1][0]);
        $this->assertSame('PT Pelanggan', $lines[1][1]);
        $this->assertSame('', $lines[1][2]);
        $this->assertSame('06', $lines[1][4]);
        $this->assertSame('2026', $lines[1][5]);
        $this->assertSame('1000000', $lines[1][6]);
        $this->assertSame('110000', $lines[1][7]);
        $this->assertSame('1110000', $lines[1][8]);
        $this->assertSame('INV-1', $lines[1][9]);
    }

    public function test_purchase_export_fills_tax_invoice_number_from_vendor(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $vendor = Contact::query()->create(['contact_code' => 'V1', 'name' => 'PT Pemasok', 'contact_type' => 'company', 'is_customer' => false, 'is_supplier' => true, 'is_employee' => false, 'is_active' => true, 'tax_number' => '09.876.543.2-109.000'])->id;

        $this->insertBill('BILL-1', '2026-06-05', $vendor, 'FP-001', 'posted', true, 1000000, 110000);
        // Excluded: void.
        $this->insertBill('BILL-VOID', '2026-06-11', $vendor, 'FP-X', 'void', true, 9000000, 990000);

        $res = $this->get('/api/reports/tax/efaktur/purchase?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers']);
        $res->assertStatus(200);

        $lines = $this->csvLines($res->streamedContent());
        $this->assertCount(2, $lines); // header + 1 row

        $this->assertSame('09.876.543.2-109.000', $lines[1][0]);
        $this->assertSame('PT Pemasok', $lines[1][1]);
        $this->assertSame('FP-001', $lines[1][2]); // vendor faktur pajak number
        $this->assertSame('BILL-1', $lines[1][9]); // referensi = internal bill number
    }

    /**
     * @return list<list<string>>
     */
    private function csvLines(string $content): array
    {
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', trim($content)) as $line) {
            if ($line === '') {
                continue;
            }
            $rows[] = str_getcsv($line);
        }

        return $rows;
    }

    private function insertInvoice(string $number, string $date, int $customerId, string $status, bool $taxable, float $dpp, float $ppn): void
    {
        DB::connection('tenant')->table('sales_invoices')->insert([
            'invoice_number' => $number,
            'invoice_date' => $date,
            'customer_id' => $customerId,
            'is_taxable' => $taxable,
            'status' => $status,
            'subtotal_after_discount' => $dpp,
            'tax_total' => $ppn,
            'grand_total' => $dpp + $ppn,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertBill(string $number, string $date, int $vendorId, ?string $vendorInvoice, string $status, bool $taxable, float $dpp, float $ppn): void
    {
        DB::connection('tenant')->table('vendor_bills')->insert([
            'bill_number' => $number,
            'bill_date' => $date,
            'vendor_id' => $vendorId,
            'vendor_invoice_number' => $vendorInvoice,
            'is_taxable' => $taxable,
            'status' => $status,
            'subtotal_after_discount' => $dpp,
            'tax_total' => $ppn,
            'grand_total' => $dpp + $ppn,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
