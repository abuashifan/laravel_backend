<?php

namespace Tests\Feature\Purchase;

use App\Modules\Journal\Models\JournalEntry;
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

        $previewUrl = '/api/purchase/payments/cash-balance?cash_bank_account_id='.$accounts['cash'].'&payment_date=2026-05-20';
        $this->getJson($previewUrl, $ctx['headers'])->assertOk()->assertJsonPath('data.balance', -100);
        $this->getJson($previewUrl.'&payment_id='.$payment['id'], $ctx['headers'])->assertOk()->assertJsonPath('data.balance', 0);

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

        $this->getJson('/api/purchase/payments/vendor-context?vendor_id='.$vendorId, $ctx['headers'])
            ->assertOk()->assertJsonPath('data.open_bills.0.bill_id', $bill['id'])
            ->assertJsonPath('data.open_bills.0.balance_due', 222);

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

    public function test_cash_preview_uses_only_current_posted_entries_through_the_payment_date(): void
    {
        $ctx = $this->setUpTenant();
        $accounts = $this->seedPurchaseMappings();
        foreach ([
            ['CASH-BASE', '2026-05-19', 1000, 'posted', false],
            ['CASH-FUTURE', '2026-05-21', 500, 'posted', false],
            ['CASH-DRAFT', '2026-05-20', 700, 'draft', false],
            ['CASH-VOID', '2026-05-20', 900, 'void', false],
            ['CASH-OLD', '2026-05-20', 300, 'posted', true],
        ] as [$number, $date, $amount, $status, $obsolete]) {
            $entry = JournalEntry::query()->create([
                'journal_number' => $number, 'journal_date' => $date,
                'status' => $status, 'is_obsolete' => $obsolete,
            ]);
            $entry->lines()->createMany([
                ['account_id' => $accounts['cash'], 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $accounts['ap'], 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ]);
        }
        $url = '/api/purchase/payments/cash-balance?cash_bank_account_id='.$accounts['cash'];
        $this->getJson($url.'&payment_date=2026-05-20', $ctx['headers'])->assertOk()
            ->assertJsonPath('data.balance', 1000)->assertJsonPath('data.as_of', '2026-05-20');
        $this->getJson($url.'&payment_date=2026-05-21', $ctx['headers'])->assertOk()->assertJsonPath('data.balance', 1500);
        $emptyCash = $this->createAccount('asset', 'CASH-EMPTY', true);
        $this->getJson('/api/purchase/payments/cash-balance?cash_bank_account_id='.$emptyCash.'&payment_date=2026-05-20', $ctx['headers'])
            ->assertOk()->assertJsonPath('data.balance', 0);
        $this->getJson('/api/purchase/payments/cash-balance?cash_bank_account_id='.$accounts['ap'].'&payment_date=2026-05-20', $ctx['headers'])
            ->assertUnprocessable();
        $this->getJson($url.'&payment_date=invalid', $ctx['headers'])->assertUnprocessable();
    }
}
