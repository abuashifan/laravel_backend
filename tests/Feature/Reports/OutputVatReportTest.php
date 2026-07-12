<?php

namespace Tests\Feature\Reports;

use App\Modules\MasterData\Models\Contact;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Journal\JournalTestCase;

class OutputVatReportTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access(): void
    {
        $this->getJson('/api/reports/tax/output-vat')->assertStatus(401);
    }

    public function test_permission_denied(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/reports/tax/output-vat', $ctx['headers'])->assertStatus(403);
    }

    public function test_invalid_date_returns_422(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $this->getJson('/api/reports/tax/output-vat?start_date=not-a-date', $ctx['headers'])->assertStatus(422);
    }

    public function test_lists_taxable_posted_invoices_with_dpp_ppn_totals(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $customer = Contact::query()->create(['contact_code' => 'C1', 'name' => 'PT Pelanggan', 'contact_type' => 'company', 'is_customer' => true, 'is_supplier' => false, 'is_employee' => false, 'is_active' => true])->id;

        // Included: posted + paid, taxable, in period.
        $this->insertInvoice('INV-1', '2026-06-05', $customer, 'posted', true, 1000000, 110000);
        $this->insertInvoice('INV-2', '2026-06-20', $customer, 'paid', true, 2000000, 220000);
        // Excluded: non-taxable, draft, out-of-period.
        $this->insertInvoice('INV-NT', '2026-06-10', $customer, 'posted', false, 5000000, 0);
        $this->insertInvoice('INV-DRF', '2026-06-11', $customer, 'draft', true, 9000000, 990000);
        $this->insertInvoice('INV-OLD', '2026-05-30', $customer, 'posted', true, 3000000, 330000);

        $res = $this->getJson('/api/reports/tax/output-vat?start_date=2026-06-01&end_date=2026-06-30', $ctx['headers'])->assertStatus(200);

        $rows = $res->json('data.rows');
        $this->assertCount(2, $rows);
        $this->assertSame('INV-1', $rows[0]['invoice_number']);
        $this->assertSame('PT Pelanggan', $rows[0]['customer_name']);
        $this->assertSame(1000000.0, (float) $rows[0]['dpp']);
        $this->assertSame(110000.0, (float) $rows[0]['ppn']);

        $this->assertSame(2, $res->json('data.totals.invoice_count'));
        $this->assertSame(3000000.0, (float) $res->json('data.totals.dpp'));
        $this->assertSame(330000.0, (float) $res->json('data.totals.ppn'));
        $this->assertSame(3330000.0, (float) $res->json('data.totals.total'));
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
}
