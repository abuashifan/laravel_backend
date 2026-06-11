<?php

namespace Tests\Feature\Purchase;

use Illuminate\Support\Facades\DB;

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

    public function test_unapplied_vendor_deposit_is_exposure_only_until_allocated(): void
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
            'amount' => 30,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/purchase/ap/vendor-summary', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.official_ap_balance', 222)
            ->assertJsonPath('data.0.unapplied_deposit_total', 30)
            ->assertJsonPath('data.0.net_vendor_exposure', 192);

        $this->getJson('/api/purchase/ap/aging', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.total', 222);

        $this->postJson('/api/purchase/vendor-deposits/'.$deposit['id'].'/allocate-to-bill/'.$bill['id'], [
            'allocated_amount' => 30,
            'allocation_date' => '2026-05-20',
        ], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/purchase/ap/vendor-summary', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.official_ap_balance', 192)
            ->assertJsonPath('data.0.unapplied_deposit_total', 0)
            ->assertJsonPath('data.0.net_vendor_exposure', 192);

        $this->getJson('/api/purchase/ap/aging', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.total', 192);
    }

    public function test_reconciliation_uses_vendor_bill_payable_snapshots_across_multiple_ap_accounts(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $apA = $this->createAccount('liability', 'APA-'.uniqid());
        $apB = $this->createAccount('liability', 'APB-'.uniqid());
        $vendorA = $this->createVendor(['name' => 'Vendor AP A']);
        $vendorB = $this->createVendor(['name' => 'Vendor AP B']);

        $billA = $this->postJson('/api/purchase/bills', $this->vendorBillPayload([
            'vendor_id' => $vendorA,
            'is_taxable' => false,
            'lines' => [['description' => 'Service A', 'quantity' => 1, 'unit_price' => 100, 'tax_rate' => 0]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        DB::connection('tenant')->table('vendor_bills')->where('id', $billA['id'])->update(['ap_account_id' => $apA]);
        $this->patchJson('/api/purchase/bills/'.$billA['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ap_account_id', $apA);

        $billB = $this->postJson('/api/purchase/bills', $this->vendorBillPayload([
            'vendor_id' => $vendorB,
            'is_taxable' => false,
            'lines' => [['description' => 'Service B', 'quantity' => 1, 'unit_price' => 200, 'tax_rate' => 0]],
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        DB::connection('tenant')->table('vendor_bills')->where('id', $billB['id'])->update(['ap_account_id' => $apB]);
        $this->patchJson('/api/purchase/bills/'.$billB['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ap_account_id', $apB);

        $payment = $this->postJson('/api/purchase/payments', [
            'vendor_id' => $vendorA,
            'vendor_bill_id' => $billA['id'],
            'payment_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 40,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/purchase/ap/reconciliation', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_reconciled', true)
            ->assertJsonPath('data.subsidiary_balance', 260)
            ->assertJsonPath('data.gl_ap_balance', 260);

        $this->getJson('/api/purchase/ap/open-bills', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.ap_account_id', $apA)
            ->assertJsonPath('data.1.ap_account_id', $apB);
    }
}
