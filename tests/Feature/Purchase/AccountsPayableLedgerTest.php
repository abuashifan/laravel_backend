<?php

namespace Tests\Feature\Purchase;

class AccountsPayableLedgerTest extends PurchaseTestCase
{
    public function test_vendor_bill_payment_deposit_and_return_create_ap_movements_and_reconcile(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $vendorId = $this->createVendor();

        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload([
            'vendor_id' => $vendorId,
            'is_taxable' => false,
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $deposit = $this->postJson('/api/purchase/vendor-deposits', [
            'vendor_id' => $vendorId,
            'deposit_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 50,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $this->postJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/allocate-to-bill/'.$bill['id'], ['amount' => 50], $ctx['headers'])->assertStatus(200);

        $payment = $this->postJson('/api/purchase/payments', [
            'vendor_id' => $vendorId,
            'vendor_bill_id' => $bill['id'],
            'payment_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 50,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $return = $this->postJson('/api/purchase/returns/from-bill/'.$bill['id'], [
            'lines' => [[
                'vendor_bill_line_id' => $bill['lines'][0]['id'],
                'description' => $bill['lines'][0]['description'],
                'quantity' => 1,
                'unit_price' => 100,
                'tax_amount' => 22,
                'line_total' => 122,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/returns/'.$return['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $ledger = $this->getJson('/api/purchase/ap/vendors/'.$vendorId.'/ledger', $ctx['headers'])
            ->assertStatus(200)
            ->json('data.movements');

        $this->assertSame(['vendor_bill', 'vendor_deposit_allocation', 'vendor_payment', 'purchase_return'], array_column($ledger, 'document_type'));
        $this->assertSame(0.0, (float) end($ledger)['balance']);

        $this->getJson('/api/purchase/ap/reconciliation', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_reconciled', true)
            ->assertJsonPath('data.subsidiary_balance', 0);
    }

    public function test_open_bills_and_vendor_summary(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedPurchaseMappings();
        $vendorId = $this->createVendor();
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload(['vendor_id' => $vendorId, 'is_taxable' => false]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/purchase/ap/open-bills', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.bill_id', $bill['id']);

        $this->getJson('/api/purchase/ap/vendor-summary', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.vendor_id', $vendorId)
            ->assertJsonPath('data.0.balance', 222);
    }
}
