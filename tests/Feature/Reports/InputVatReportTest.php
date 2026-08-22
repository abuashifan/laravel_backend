<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\Contact;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

class InputVatReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/tax/input-vat')->assertStatus(401);
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/tax/input-vat', $ctx['headers'])->assertStatus(403);
    }

    public function test_lists_taxable_posted_bills_with_dpp_ppn_totals(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $vendor = Contact::query()->create(['contact_code' => 'V1', 'name' => 'PT Pemasok', 'contact_type' => 'company', 'is_customer' => false, 'is_supplier' => true, 'is_employee' => false, 'is_active' => true])->id;

        $this->insertBill('BILL-1', '2026-06-05', $vendor, 'FP-001', 'posted', true, 1000000, 110000);
        $this->insertBill('BILL-2', '2026-06-20', $vendor, 'FP-002', 'paid', true, 500000, 55000);
        // Excluded: non-taxable, void, out-of-period.
        $this->insertBill('BILL-NT', '2026-06-10', $vendor, null, 'posted', false, 5000000, 0);
        $this->insertBill('BILL-VOID', '2026-06-11', $vendor, 'FP-X', 'void', true, 9000000, 990000);
        $this->insertBill('BILL-OLD', '2026-05-30', $vendor, 'FP-OLD', 'posted', true, 3000000, 330000);

        $res = $this->getJson('/api/reports/tax/input-vat?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])->assertStatus(200);

        $rows = $res->json('data.rows');
        $this->assertCount(2, $rows);
        $this->assertSame('BILL-1', $rows[0]['bill_number']);
        $this->assertSame('FP-001', $rows[0]['vendor_invoice_number']);
        $this->assertSame('PT Pemasok', $rows[0]['vendor_name']);

        $this->assertSame(2, $res->json('data.totals.bill_count'));
        $this->assertSame(1500000.0, (float) $res->json('data.totals.dpp'));
        $this->assertSame(165000.0, (float) $res->json('data.totals.ppn'));
        $this->assertSame(1665000.0, (float) $res->json('data.totals.total'));
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
