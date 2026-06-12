<?php

declare(strict_types=1);

namespace Tests\Feature\Sales;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\Contact;
use App\Models\Tenant\SalesInvoice;
use App\Support\AccountMapping\AccountMappingKey;
use Tests\TenantTestCase;

class InvoiceBalanceMatrixTest extends TenantTestCase
{
    /** @var array<string, int> */
    protected array $accounts;

    protected Contact $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->accounts = $this->seedSalesMappings();
        $this->customer = Contact::factory()->customer()->create([
            'name' => 'M8 Customer '.uniqid(),
        ]);
    }

    protected function tenantRole(): string
    {
        return 'owner';
    }

    protected function accountingSettingOverrides(): array
    {
        return [
            'transaction_workflow_mode' => 'draft_then_post',
            'auto_post_transactions' => false,
            'approval_enabled' => false,
        ];
    }

    // -----------------------------------------------------------------------
    // Scenario 1 — Post invoice: balance = grand_total (harus PASS)
    // -----------------------------------------------------------------------

    public function test_posted_invoice_has_balance_due_equal_to_grand_total(): void
    {
        $invoice = $this->postInvoice(1000.0);

        $this->assertSame('posted', $invoice->status);
        $this->assertSame(1000.0, (float) $invoice->grand_total);
        $this->assertSame(1000.0, (float) $invoice->balance_due);
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertSame(0.0, (float) $invoice->returned_amount);
    }

    // -----------------------------------------------------------------------
    // Scenario 2 — Partial receipt: balance berkurang (harus PASS)
    // -----------------------------------------------------------------------

    public function test_partial_receipt_reduces_balance_due_correctly(): void
    {
        $invoice = $this->postInvoice(1000.0);
        $this->postReceipt($invoice, 600.0);

        $invoice->refresh();
        $this->assertSame(400.0, (float) $invoice->balance_due);
        $this->assertSame(600.0, (float) $invoice->paid_amount);
        $this->assertSame('partially_paid', $invoice->status);
    }

    // -----------------------------------------------------------------------
    // Scenario 3 — Full receipt: status paid, balance = 0 (harus PASS)
    // -----------------------------------------------------------------------

    public function test_full_receipt_sets_status_to_paid_and_balance_due_to_zero(): void
    {
        $invoice = $this->postInvoice(1000.0);
        $this->postReceipt($invoice, 1000.0);

        $invoice->refresh();
        $this->assertSame(0.0, (float) $invoice->balance_due);
        $this->assertSame(1000.0, (float) $invoice->paid_amount);
        $this->assertSame('paid', $invoice->status);
    }

    // -----------------------------------------------------------------------
    // Scenario 4 — Deposit allocation: balance berkurang (harus PASS)
    // -----------------------------------------------------------------------

    public function test_customer_deposit_allocation_reduces_balance_due(): void
    {
        $invoice = $this->postInvoice(1000.0);
        $this->allocateDeposit($invoice, 400.0);

        $invoice->refresh();
        $this->assertSame(600.0, (float) $invoice->balance_due);
        $this->assertSame(400.0, (float) $invoice->paid_amount);
        $this->assertSame('partially_paid', $invoice->status);
    }

    // -----------------------------------------------------------------------
    // Scenario 5 — Partial return: returned_amount naik, balance berkurang
    // -----------------------------------------------------------------------

    public function test_partial_return_increases_returned_amount_and_reduces_balance_due(): void
    {
        $invoice = $this->postInvoice(1000.0);
        $this->postReturn($invoice, 200.0);

        $invoice->refresh();
        $this->assertSame(200.0, (float) $invoice->returned_amount);
        $this->assertSame(800.0, (float) $invoice->balance_due);
        // SalesReturnService::updateInvoice() tidak mengupdate status —
        // invoice tetap 'posted' karena belum ada pembayaran.
        $this->assertSame('posted', $invoice->status);
    }

    // -----------------------------------------------------------------------
    // Scenario 6 — Receipt + return kombinasi
    // -----------------------------------------------------------------------

    public function test_combination_of_receipt_and_return_calculates_balance_due_correctly(): void
    {
        $invoice = $this->postInvoice(1000.0);

        $this->postReceipt($invoice, 600.0);
        $invoice->refresh();
        $this->assertSame(400.0, (float) $invoice->balance_due);

        $this->postReturn($invoice, 200.0);
        $invoice->refresh();
        $this->assertSame(200.0, (float) $invoice->balance_due);
        $this->assertSame(600.0, (float) $invoice->paid_amount);
        $this->assertSame(200.0, (float) $invoice->returned_amount);
        // Status tetap 'partially_paid' — return tidak mengubah status.
        $this->assertSame('partially_paid', $invoice->status);
    }

    // -----------------------------------------------------------------------
    // Scenario 7 — Balance tidak boleh negatif: overpayment diblokir
    // -----------------------------------------------------------------------

    public function test_balance_due_cannot_go_negative_after_overpayment_prevention(): void
    {
        $invoice = $this->postInvoice(1000.0);

        // Create draft receipt dulu (create tidak validasi overpayment)
        $receiptData = $this->postJson('/api/sales/receipts', [
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-05-20',
            'amount' => 1200.0,
            'cash_bank_account_id' => $this->accounts['cash'],
            'sales_invoice_id' => $invoice->id,
        ], $this->headers);

        if ($receiptData->status() === 201) {
            // Post harus gagal dengan 422 (OVERPAYMENT_NOT_ALLOWED)
            $this->patchJson(
                '/api/sales/receipts/'.$receiptData->json('data.id').'/post',
                [],
                $this->headers
            )->assertStatus(422);
        } else {
            // Jika create langsung ditolak, pastikan 422
            $receiptData->assertStatus(422);
        }

        $invoice->refresh();
        $this->assertSame(1000.0, (float) $invoice->balance_due);
    }

    // -----------------------------------------------------------------------
    // Scenario 8 — Full return: status aktual dikonfirmasi (GAP TERKONFIRMASI)
    // -----------------------------------------------------------------------

    public function test_full_return_sets_invoice_to_correct_final_status(): void
    {
        $invoice = $this->postInvoice(1000.0);
        $this->postReturn($invoice, 1000.0);

        $invoice->refresh();
        $this->assertSame(1000.0, (float) $invoice->returned_amount);
        $this->assertSame(0.0, (float) $invoice->balance_due);

        $this->assertSame('returned', $invoice->status);
    }

    // -----------------------------------------------------------------------
    // Scenario 9 — Multiple transactions: running balance benar
    // -----------------------------------------------------------------------

    public function test_multiple_transactions_maintain_correct_running_balance(): void
    {
        $invoice = $this->postInvoice(1000.0);

        $this->postReceipt($invoice, 400.0);
        $invoice->refresh();
        $this->assertSame(600.0, (float) $invoice->balance_due);
        $this->assertSame('partially_paid', $invoice->status);

        $this->postReturn($invoice, 100.0);
        $invoice->refresh();
        $this->assertSame(500.0, (float) $invoice->balance_due);
        $this->assertSame(100.0, (float) $invoice->returned_amount);

        $this->postReceipt($invoice, 300.0);
        $invoice->refresh();
        $this->assertSame(200.0, (float) $invoice->balance_due);
        $this->assertSame(700.0, (float) $invoice->paid_amount);
        $this->assertSame(100.0, (float) $invoice->returned_amount);
        $this->assertSame('partially_paid', $invoice->status);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Post invoice dengan grand_total tertentu.
     * Struktur: qty=10, unit_price=grandTotal/10, total=grandTotal.
     * Ini memungkinkan return partial dengan integer quantity (amount/unitPrice).
     */
    private function postInvoice(float $grandTotal): SalesInvoice
    {
        $unitPrice = $grandTotal / 10;

        $data = $this->postJson('/api/sales/invoices', [
            'customer_id' => $this->customer->id,
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-05-30',
            'is_taxable' => false,
            'tax_included' => false,
            'lines' => [
                [
                    'description' => 'Service Item M8',
                    'quantity' => 10,
                    'unit_price' => $unitPrice,
                ],
            ],
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/sales/invoices/'.$data['id'].'/post', [], $this->headers)
            ->assertSuccessful();

        return SalesInvoice::query()->findOrFail($data['id']);
    }

    /**
     * Buat dan post receipt untuk invoice dengan amount tertentu.
     * Gunakan sales_invoice_id (bukan lines array) agar service auto-buat line.
     */
    private function postReceipt(SalesInvoice $invoice, float $amount): void
    {
        $receipt = $this->postJson('/api/sales/receipts', [
            'customer_id' => $this->customer->id,
            'receipt_date' => '2026-05-20',
            'amount' => $amount,
            'cash_bank_account_id' => $this->accounts['cash'],
            'sales_invoice_id' => $invoice->id,
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $this->headers)
            ->assertSuccessful();
    }

    /**
     * Buat dan post sales return untuk invoice dengan amount tertentu.
     * Unit price per unit = grand_total / 10 (10 qty per invoice).
     * quantity = amount / unitPrice → integer quantity untuk precision check.
     */
    private function postReturn(SalesInvoice $invoice, float $amount): void
    {
        $invoice->loadMissing('lines');
        $firstLine = $invoice->lines->first();
        $unitPrice = (float) $firstLine->unit_price;
        $quantity = $unitPrice > 0 ? round($amount / $unitPrice) : 1;

        $return = $this->postJson('/api/sales/returns', [
            'customer_id' => $this->customer->id,
            'return_date' => '2026-05-20',
            'sales_invoice_id' => $invoice->id,
            'lines' => [
                [
                    'sales_invoice_line_id' => $firstLine->id,
                    'description' => (string) $firstLine->description,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'discount_amount' => 0,
                    'tax_amount' => 0,
                    'line_total' => $amount,
                ],
            ],
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $this->headers)
            ->assertSuccessful();
    }

    /**
     * Buat deposit, post, lalu alokasikan ke invoice.
     */
    private function allocateDeposit(SalesInvoice $invoice, float $amount): void
    {
        $deposit = $this->postJson('/api/sales/customer-deposits', [
            'customer_id' => $this->customer->id,
            'deposit_date' => '2026-05-20',
            'amount' => $amount,
            'cash_bank_account_id' => $this->accounts['cash'],
        ], $this->headers)->assertCreated()->json('data');

        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $this->headers)
            ->assertSuccessful();

        $this->postJson(
            '/api/sales/customer-deposits/'.$deposit['id'].'/allocate-to-invoice/'.$invoice->id,
            ['amount' => $amount],
            $this->headers
        )->assertSuccessful();
    }

    /**
     * Seed account mappings untuk sales flow.
     *
     * JournalTestCase::setUpTenant() sudah pre-seed:
     *   account_code='1000' → Cash (asset, is_cash_bank=true)
     *   account_code='4000' → Revenue (revenue)
     * Kode-kode tersebut direuse, bukan dibuat ulang.
     *
     * @return array<string, int>
     */
    private function seedSalesMappings(): array
    {
        // Reuse accounts pre-seeded oleh JournalTestCase
        $cashAccount    = ChartOfAccount::query()->where('account_code', '1000')->firstOrFail();
        $revenueAccount = ChartOfAccount::query()->where('account_code', '4000')->firstOrFail();

        // Buat accounts tambahan dengan kode yang belum ada
        $accounts = [
            'ar'         => ChartOfAccount::factory()->asset()->create(['account_code' => '1200', 'account_name' => 'Accounts Receivable'])->id,
            'revenue'    => (int) $revenueAccount->id,
            'deposit'    => ChartOfAccount::factory()->liability()->create(['account_code' => '2200', 'account_name' => 'Customer Deposit'])->id,
            'return'     => ChartOfAccount::factory()->expense()->create(['account_code' => '4900', 'account_name' => 'Sales Return'])->id,
            'tax_output' => ChartOfAccount::factory()->liability()->create(['account_code' => '2300', 'account_name' => 'Tax Output'])->id,
            'cash'       => (int) $cashAccount->id,
        ];

        $mappings = [
            AccountMappingKey::SALES_ACCOUNTS_RECEIVABLE => ['sales', $accounts['ar']],
            AccountMappingKey::SALES_REVENUE             => ['sales', $accounts['revenue']],
            AccountMappingKey::SALES_CUSTOMER_DEPOSIT    => ['sales', $accounts['deposit']],
            AccountMappingKey::SALES_RETURN              => ['sales', $accounts['return']],
            AccountMappingKey::SALES_TAX_OUTPUT          => ['sales', $accounts['tax_output']],
        ];

        foreach ($mappings as $key => [$module, $accountId]) {
            AccountMapping::factory()->create([
                'mapping_key' => $key,
                'module'      => $module,
                'account_id'  => $accountId,
                'is_required' => true,
                'is_active'   => true,
            ]);
        }

        return array_map('intval', $accounts);
    }
}
