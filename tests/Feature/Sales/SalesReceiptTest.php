<?php

namespace Tests\Feature\Sales;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Sales\Models\CustomerDeposit;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReceipt;

class SalesReceiptTest extends SalesTestCase
{
    public function test_create_and_post_receipt_for_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);

        $receipt = $this->postJson('/api/sales/receipts', $this->receiptPayload($cash, $invoice, 40), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->json('data');

        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(2, JournalEntry::query()->count());
        $this->assertSame('partially_paid', SalesInvoice::query()->find($invoice['id'])->status);
    }

    public function test_invoice_status_paid_and_prevent_overpayment(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $receipt = $this->postJson('/api/sales/receipts', $this->receiptPayload($cash, $invoice, 100), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $this->assertSame('paid', SalesInvoice::query()->find($invoice['id'])->status);

        $over = $this->postJson('/api/sales/receipts', $this->receiptPayload($cash, SalesInvoice::query()->find($invoice['id'])->toArray(), 1), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$over['id'].'/post', [], $ctx['headers'])->assertStatus(422);
    }

    public function test_receipt_customer_context_and_apply_deposit_before_receipt(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $deposit = CustomerDeposit::query()->create([
            'deposit_number' => 'CD-RCPT-1',
            'deposit_date' => '2026-05-20',
            'customer_id' => $invoice['customer_id'],
            'cash_bank_account_id' => $cash,
            'amount' => 25,
            'remaining_amount' => 25,
            'allocated_amount' => 0,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $this->getJson('/api/sales/receipts/customer-context?customer_id='.$invoice['customer_id'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.gross_ar_outstanding', 100)
            ->assertJsonPath('data.unapplied_deposit_total', 25)
            ->assertJsonPath('data.net_customer_exposure', 75)
            ->assertJsonPath('data.available_deposits.0.match_strength', 'customer_only');

        $this->postJson('/api/sales/customer-deposits/'.$deposit->id.'/allocate-to-invoice/'.$invoice['id'], [
            'allocated_amount' => 25,
            'source_context' => 'sales_receipt',
            'allocation_date' => '2026-05-20',
        ], $ctx['headers'])->assertStatus(200);

        $refreshed = SalesInvoice::query()->findOrFail($invoice['id'])->toArray();
        $receipt = $this->postJson('/api/sales/receipts', $this->receiptPayload($cash, $refreshed, 75), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->assertSame('paid', SalesInvoice::query()->findOrFail($invoice['id'])->status);
        $this->assertSame(75.0, (float) SalesReceipt::query()->findOrFail($receipt['id'])->amount);
    }

    public function test_void_receipt_permission_and_tenant_isolation(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $receipt = $this->postJson('/api/sales/receipts', $this->receiptPayload($cash, $invoice, 10), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/void', ['reason' => 'Wrong'], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'void');
        $this->assertSame('void', JournalEntry::query()->where('source_type', 'sales_receipt')->firstOrFail()->status);
        $restored = SalesInvoice::query()->findOrFail($invoice['id']);
        $this->assertSame('posted', $restored->status);
        $this->assertSame(0.0, (float) $restored->paid_amount);

        $viewer = $this->setUpTenant('viewer');
        $this->postJson('/api/sales/receipts', $this->receiptPayload(1, ['id' => 1, 'customer_id' => 1], 1), $viewer['headers'])->assertStatus(403);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, SalesReceipt::query()->count());
    }

    public function test_one_receipt_posts_discounts_per_invoice_and_preserves_cash_and_reconciliation(): void
    {
        $ctx = $this->setUpTenant();
        $headers = $ctx['headers'];
        $cash = $this->seedMappings();
        $first = $this->postedInvoice($ctx);
        $second = $this->postedInvoice($ctx, $first['customer_id']);
        $discountA = $this->account('4120', 'Potongan pelanggan', 'revenue', 'debit');
        $discountB = $this->account('6101', 'Potongan program', 'expense', 'debit');
        $payload = [
            'receipt_date' => '2026-05-20', 'customer_id' => $first['customer_id'],
            'cash_bank_account_id' => $cash, 'amount' => 137.50,
            'lines' => [
                ['sales_invoice_id' => $first['id'], 'amount' => 92.50, 'settlement_discount_amount' => 7.50,
                    'settlement_discount_account_id' => $discountA, 'settlement_discount_note' => 'Kesepakatan pelanggan'],
                ['sales_invoice_id' => $second['id'], 'amount' => 45, 'settlement_discount_amount' => 5,
                    'settlement_discount_account_id' => $discountB],
            ],
        ];
        $missing = $payload;
        unset($missing['lines'][0]['settlement_discount_account_id']);
        $this->postJson('/api/sales/receipts', $missing, $headers)->assertUnprocessable();
        $wrong = $payload;
        $wrong['lines'][0]['settlement_discount_account_id'] = $cash;
        $this->postJson('/api/sales/receipts', $wrong, $headers)->assertUnprocessable();

        $receipt = $this->postJson('/api/sales/receipts', $payload, $headers)->assertCreated()->json('data.id');
        $url = '/api/sales/receipts/'.$receipt;
        $this->patchJson($url.'/post', [], $headers)->assertOk();
        $this->patchJson($url.'/post', [], $headers)->assertUnprocessable();
        $this->getJson($url, $headers)->assertOk()
            ->assertJsonPath('data.lines.0.settlement_discount_account.id', $discountA);
        $this->assertSame('paid', SalesInvoice::query()->findOrFail($first['id'])->status);
        $this->assertSame(92.50, (float) SalesInvoice::query()->findOrFail($first['id'])->paid_amount);
        $this->assertSame(50.0, (float) SalesInvoice::query()->findOrFail($second['id'])->balance_due);
        $journal = JournalEntry::query()->where('source_type', 'sales_receipt')->where('source_id', $receipt)->firstOrFail();
        $this->assertSame(137.50, (float) $journal->lines()->where('account_id', $cash)->sum('debit'));
        $this->assertSame(7.50, (float) $journal->lines()->where('account_id', $discountA)->sum('debit'));
        $this->assertSame(5.0, (float) $journal->lines()->where('account_id', $discountB)->sum('debit'));
        $this->assertSame(150.0, (float) $journal->lines()->sum('credit'));
        $this->getJson('/api/sales/ar/invoices/'.$second['id'].'/ledger', $headers)->assertOk()
            ->assertJsonPath('data.movements.1.credit', 50)
            ->assertJsonPath('data.movements.1.settlement_discount_amount', 5);
        $this->getJson('/api/sales/ar/reconciliation', $headers)->assertOk()->assertJsonPath('data.difference', 0);

        $this->patchJson($url.'/void', ['reason' => 'Koreksi penerimaan'], $headers)->assertOk();
        foreach ([$first, $second] as $invoice) {
            $restored = SalesInvoice::query()->findOrFail($invoice['id']);
            $this->assertSame(100.0, (float) $restored->balance_due);
            $this->assertSame(0.0, (float) $restored->settlement_discount_amount);
        }
        $this->getJson('/api/sales/ar/reconciliation', $headers)->assertOk()->assertJsonPath('data.difference', 0);
    }

    private function receiptPayload(int $cash, array $invoice, float $amount): array
    {
        return ['receipt_date' => '2026-05-20', 'customer_id' => $invoice['customer_id'], 'sales_invoice_id' => $invoice['id'], 'cash_bank_account_id' => $cash, 'amount' => $amount];
    }

    private function postedInvoice(array $ctx, ?int $customer = null): array
    {
        $invoice = $this->postJson('/api/sales/invoices', ['customer_id' => $customer ?? $this->createCustomer(), 'invoice_date' => '2026-05-20', 'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]]], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        return SalesInvoice::query()->find($invoice['id'])->toArray();
    }

    private function seedMappings(): int
    {
        $cash = $this->account('1000', 'Cash', 'asset', 'debit', true);
        $ar = $this->account('1100', 'AR', 'asset', 'debit');
        $revenue = $this->account('4100', 'Revenue', 'revenue', 'credit');
        $deposit = $this->account('2200', 'Customer Deposit', 'liability', 'credit');
        foreach (['sales.accounts_receivable' => $ar, 'sales.revenue' => $revenue, 'sales.customer_deposit' => $deposit] as $key => $id) {
            AccountMapping::query()->create(['mapping_key' => $key, 'module' => 'sales', 'account_id' => $id, 'is_required' => true, 'is_active' => true]);
        }

        return $cash;
    }

    private function account(string $code, string $name, string $type, string $normal, bool $cash = false): int
    {
        return (int) ChartOfAccount::query()->create(['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_cash_bank' => $cash, 'is_active' => true])->id;
    }
}
