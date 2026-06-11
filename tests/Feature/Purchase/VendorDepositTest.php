<?php

namespace Tests\Feature\Purchase;

use Illuminate\Support\Facades\DB;

class VendorDepositTest extends PurchaseTestCase
{
    public function test_create_and_post_deposit(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();

        $deposit = $this->postJson('/api/purchase/vendor-deposits', [
            'vendor_id' => $this->createVendor(),
            'deposit_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 75,
        ], $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, DB::connection('tenant')->table('journal_entries')->where('source_type', 'vendor_deposit')->count());
    }

    public function test_cannot_allocate_more_than_remaining(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $vendorId = $this->createVendor();
        $deposit = $this->postJson('/api/purchase/vendor-deposits', ['vendor_id' => $vendorId, 'deposit_date' => '2026-05-20', 'cash_bank_account_id' => $accounts['cash'], 'amount' => 50], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['vendor_id' => $vendorId, 'is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/allocate-to-bill/'.$bill['id'], ['amount' => 60], $ctx['headers'])->assertStatus(422);
    }

    public function test_available_endpoint_and_payment_context_allocation_metadata(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $vendorId = $this->createVendor();
        $deposit = $this->postJson('/api/purchase/vendor-deposits', [
            'vendor_id' => $vendorId,
            'deposit_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 60,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['vendor_id' => $vendorId, 'is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/purchase/vendor-deposits/available?vendor_id='.$vendorId.'&vendor_bill_id='.$bill['id'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.unapplied_total', 60)
            ->assertJsonPath('data.deposits.0.match_strength', 'vendor_only');

        $this->postJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/allocate-to-bill/'.$bill['id'], [
            'allocated_amount' => 40,
            'allocation_date' => '2026-05-20',
            'source_context' => 'vendor_payment',
            'notes' => 'Applied during payment entry',
        ], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.metadata.source_context', 'vendor_payment');

        $allocation = DB::connection('tenant')->table('vendor_deposit_allocations')->first();
        $this->assertSame(20.0, (float) DB::connection('tenant')->table('vendor_deposits')->where('id', $deposit['id'])->value('remaining_amount'));
        $this->assertSame(182.0, (float) DB::connection('tenant')->table('vendor_bills')->where('id', $bill['id'])->value('balance_due'));
        $this->assertSame(40.0, (float) DB::connection('tenant')->table('journal_entry_lines')->where('journal_entry_id', $allocation->journal_entry_id)->where('description', 'Accounts Payable')->value('debit'));
        $this->assertSame(40.0, (float) DB::connection('tenant')->table('journal_entry_lines')->where('journal_entry_id', $allocation->journal_entry_id)->where('description', 'Vendor Deposit')->value('credit'));
    }

    public function test_cannot_allocate_to_bill_of_different_vendor(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $deposit = $this->postJson('/api/purchase/vendor-deposits', [
            'vendor_id' => $this->createVendor(),
            'deposit_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 50,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/allocate-to-bill/'.$bill['id'], [
            'allocated_amount' => 10,
        ], $ctx['headers'])->assertStatus(422);
    }
}
