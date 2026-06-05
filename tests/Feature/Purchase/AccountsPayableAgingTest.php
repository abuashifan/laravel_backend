<?php

namespace Tests\Feature\Purchase;

class AccountsPayableAgingTest extends PurchaseTestCase
{
    public function test_aging_buckets_and_paid_bill_exclusion(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        $vendorId = $this->createVendor();

        $this->createPostedBill($ctx, $vendorId, '2026-05-15', '2026-05-20');
        $this->createPostedBill($ctx, $vendorId, '2026-04-01', '2026-04-20');
        $this->createPostedBill($ctx, $vendorId, '2026-03-01', '2026-04-01');
        $this->createPostedBill($ctx, $vendorId, '2026-02-01', '2026-02-20');
        $this->createPostedBill($ctx, $vendorId, '2026-01-01', '2026-01-15');
        $paid = $this->createPostedBill($ctx, $vendorId, '2026-05-01', '2026-05-01');
        $payment = $this->postJson('/api/purchase/payments', [
            'vendor_id' => $vendorId,
            'vendor_bill_id' => $paid['id'],
            'payment_date' => '2026-05-20',
            'cash_bank_account_id' => $accounts['cash'],
            'amount' => 222,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/payments/'.$payment['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/purchase/ap/aging?as_of_date=2026-05-20', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.buckets.current', 222)
            ->assertJsonPath('data.buckets.1_30', 222)
            ->assertJsonPath('data.buckets.31_60', 222)
            ->assertJsonPath('data.buckets.61_90', 222)
            ->assertJsonPath('data.buckets.over_90', 222)
            ->assertJsonPath('data.total', 1110);
    }

    private function createPostedBill(array $ctx, int $vendorId, string $billDate, string $dueDate): array
    {
        $bill = $this->postJson('/api/purchase/bills', $this->vendorBillPayload([
            'vendor_id' => $vendorId,
            'bill_date' => $billDate,
            'due_date' => $dueDate,
            'is_taxable' => false,
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/purchase/bills/'.$bill['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        return $bill;
    }
}
