<?php

namespace Tests\Feature\Purchase;

use Illuminate\Support\Facades\DB;

class VendorPaymentTest extends PurchaseTestCase
{
    public function test_create_and_post_payment_for_bill(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $payment = $this->postJson('/api/purchase/payments', [
            'payment_date' => '2026-05-20',
            'vendor_id' => $bill['vendor_id'],
            'vendor_bill_id' => $bill['id'],
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 100,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_payment')->count());
        $this->assertSame(100.0, (float) DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->value('paid_amount'));

        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/void', ['reason' => 'Incorrect payment'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'void');
        $this->assertSame('void', DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_payment')->value('status'));
        $this->assertSame(0.0, (float) DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->value('paid_amount'));
    }

    public function test_prevent_overpayment(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $payment = $this->postJson('/api/purchase/payments', ['payment_date' => '2026-05-20', 'vendor_id' => $bill['vendor_id'], 'vendor_bill_id' => $bill['id'], 'cash_bank_account_id' => $accounts['cash'], 'amount' => 999], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/post', [], $ctx['headers'])->assertStatus(422);
    }

    public function test_payment_vendor_context_and_apply_deposit_before_payment(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $vendorId = $this->createVendor();
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['vendor_id' => $vendorId, 'is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $deposit = $this->postJson('/api/purchase/vendor-deposits', [
            'vendor_id' => $vendorId,
            'deposit_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 25,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/purchase/payments/vendor-context?vendor_id='.$vendorId, $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.gross_ap_outstanding', 222)
            ->assertJsonPath('data.unapplied_deposit_total', 25)
            ->assertJsonPath('data.net_vendor_exposure', 197)
            ->assertJsonPath('data.available_deposits.0.match_strength', 'vendor_only');

        $this->postJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/allocate-to-bill/'.$bill['id'], [
            'allocated_amount' => 25,
            'allocation_date' => '2026-05-20',
            'source_context' => 'vendor_payment',
        ], $ctx['headers'])->assertStatus(200);

        $payment = $this->postJson('/api/purchase/payments', [
            'payment_date' => '2026-05-20',
            'vendor_id' => $vendorId,
            'vendor_bill_id' => $bill['id'],
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 197,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame(197.0, (float) DB::connection('tenant')->table('vendor_payments')->where('id', $payment['id'])->value('amount'));
        $this->assertSame('paid', DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->value('status'));
    }
}
