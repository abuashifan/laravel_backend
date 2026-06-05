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
}
