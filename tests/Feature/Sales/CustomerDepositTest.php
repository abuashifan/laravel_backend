<?php

namespace Tests\Feature\Sales;

use App\Models\FiscalYear;
use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\JournalEntry;
use App\Models\Tenant\JournalEntryLine;
use App\Models\Tenant\SalesInvoice;

class CustomerDepositTest extends SalesTestCase
{
    public function test_create_deposit_directly(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();

        $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.remaining_amount', 100);
    }

    public function test_create_deposit_from_sales_order(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $order = $this->createOrder($ctx);

        $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, ['sales_order_id' => $order['id']]), $ctx['headers'])
            ->assertStatus(201)
            ->assertJsonPath('data.sales_order_id', $order['id']);
    }

    public function test_post_deposit_creates_journal(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash), $ctx['headers'])->assertStatus(201)->json('data');

        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        $this->assertSame(1, JournalEntry::query()->count());
    }

    public function test_allocate_deposit_to_invoice_creates_journal_and_updates_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, ['customer_id' => $invoice['customer_id'], 'amount' => 50]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/sales/customer-deposits/'.$deposit['id'].'/allocate-to-invoice/'.$invoice['id'], ['amount' => 50], $ctx['headers'])
            ->assertStatus(200);

        $this->assertSame(1, CustomerDepositAllocation::query()->count());
        $this->assertSame(50.0, (float) SalesInvoice::query()->find($invoice['id'])->paid_amount);
    }

    public function test_available_endpoint_and_receipt_context_allocation_metadata(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, [
            'customer_id' => $invoice['customer_id'],
            'amount' => 60,
        ]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/sales/customer-deposits/available?customer_id='.$invoice['customer_id'].'&sales_invoice_id='.$invoice['id'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.unapplied_total', 60)
            ->assertJsonPath('data.deposits.0.match_strength', 'customer_only');

        $this->postJson('/api/sales/customer-deposits/'.$deposit['id'].'/allocate-to-invoice/'.$invoice['id'], [
            'allocated_amount' => 40,
            'allocation_date' => '2026-05-20',
            'source_context' => 'sales_receipt',
            'notes' => 'Applied during receipt entry',
        ], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.metadata.source_context', 'sales_receipt');

        $allocation = CustomerDepositAllocation::query()->firstOrFail();
        $this->assertSame(20.0, (float) CustomerDeposit::query()->findOrFail($deposit['id'])->remaining_amount);
        $this->assertSame(60.0, (float) SalesInvoice::query()->findOrFail($invoice['id'])->balance_due);
        $this->assertSame(40.0, (float) JournalEntryLine::query()->where('journal_entry_id', $allocation->journal_entry_id)->where('description', 'Customer Deposit')->value('debit'));
        $this->assertSame(40.0, (float) JournalEntryLine::query()->where('journal_entry_id', $allocation->journal_entry_id)->where('description', 'Accounts Receivable')->value('credit'));
    }

    public function test_cannot_allocate_deposit_to_different_customer_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, ['amount' => 50]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/sales/customer-deposits/'.$deposit['id'].'/allocate-to-invoice/'.$invoice['id'], [
            'allocated_amount' => 10,
        ], $ctx['headers'])->assertStatus(422);
    }

    public function test_refund_deposit_creates_journal(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/refund', ['amount' => 100, 'reason' => 'Refund'], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'refunded');

        $this->assertSame(2, JournalEntry::query()->count());
    }

    public function test_cannot_allocate_more_than_remaining_and_void_deposit(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, ['customer_id' => $invoice['customer_id'], 'amount' => 10]), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->postJson('/api/sales/customer-deposits/'.$deposit['id'].'/allocate-to-invoice/'.$invoice['id'], ['amount' => 20], $ctx['headers'])->assertStatus(422);

        $void = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash), $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$void['id'].'/void', ['reason' => 'Wrong'], $ctx['headers'])->assertStatus(200)->assertJsonPath('data.status', 'void');
    }

    public function test_period_lock_blocks_post_and_tenant_isolation(): void
    {
        $ctxA = $this->setUpTenant();
        $cash = $this->seedMappings();
        FiscalYear::query()->create(['company_id' => $ctxA['company']->id, 'year' => 2026, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'status' => 'closed', 'is_active' => true]);
        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash), $ctxA['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctxA['headers'])->assertStatus(422);

        $ctxB = $this->setUpTenant();
        $this->assertSame(0, CustomerDeposit::query()->count());
    }

    private function depositPayload(int $cash, array $overrides = []): array
    {
        return array_merge(['deposit_date' => '2026-05-20', 'customer_id' => $this->createCustomer(), 'cash_bank_account_id' => $cash, 'amount' => 100], $overrides);
    }

    private function createOrder(array $ctx): array
    {
        return $this->postJson('/api/sales/orders', ['customer_id' => $this->createCustomer(), 'order_date' => '2026-05-20', 'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]]], $ctx['headers'])->assertStatus(201)->json('data');
    }

    private function postedInvoice(array $ctx): array
    {
        $invoice = $this->postJson('/api/sales/invoices', ['customer_id' => $this->createCustomer(), 'invoice_date' => '2026-05-20', 'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]]], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);
        return SalesInvoice::query()->find($invoice['id'])->toArray();
    }

    private function seedMappings(): int
    {
        $cash = $this->account('1000', 'Cash', 'asset', 'debit', true);
        $ar = $this->account('1100', 'AR', 'asset', 'debit');
        $revenue = $this->account('4100', 'Revenue', 'revenue', 'credit');
        $deposit = $this->account('2200', 'Deposit', 'liability', 'credit');
        foreach (['sales.accounts_receivable' => $ar, 'sales.revenue' => $revenue, 'sales.customer_deposit' => $deposit] as $key => $id) AccountMapping::query()->create(['mapping_key' => $key, 'module' => 'sales', 'account_id' => $id, 'is_required' => true, 'is_active' => true]);
        return $cash;
    }

    private function account(string $code, string $name, string $type, string $normal, bool $cash = false): int
    {
        return (int) ChartOfAccount::query()->create(['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_cash_bank' => $cash, 'is_active' => true])->id;
    }
}
