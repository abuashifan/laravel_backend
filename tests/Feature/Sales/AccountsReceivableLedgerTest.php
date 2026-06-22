<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\CustomerDeposit;
use App\Models\Tenant\CustomerDepositAllocation;
use App\Models\Tenant\JournalEntryLine;
use App\Models\Tenant\SalesInvoice;

class AccountsReceivableLedgerTest extends SalesTestCase
{
    public function test_ar_ledger_tracks_invoice_receipt_deposit_allocation_and_return(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx, 200);
        $this->postReceipt($ctx, $cash, $invoice, 50);
        $this->allocateDeposit($ctx, $cash, $invoice, 25);
        $this->postReturn($ctx, $invoice, 25);

        $this->getJson('/api/sales/ar/customers/'.$invoice['customer_id'].'/ledger', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.movements.0.document_type', 'sales_invoice')
            ->assertJsonPath('data.movements.0.debit', 200)
            ->assertJsonPath('data.movements.3.balance', 100);

        $this->getJson('/api/sales/ar/customer-summary', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.balance', 100);

        $this->getJson('/api/sales/ar/open-invoices', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.balance_due', 100);
    }

    public function test_unapplied_deposit_is_exposure_only_until_allocated(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx, 100);
        $deposit = CustomerDeposit::query()->create([
            'deposit_number' => 'CD-UNAPPLIED-1',
            'deposit_date' => '2026-05-20',
            'customer_id' => $invoice['customer_id'],
            'cash_bank_account_id' => $cash,
            'amount' => 30,
            'remaining_amount' => 30,
            'allocated_amount' => 0,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $this->getJson('/api/sales/ar/customer-summary', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.official_ar_balance', 100)
            ->assertJsonPath('data.0.unapplied_deposit_total', 30)
            ->assertJsonPath('data.0.net_customer_exposure', 70);

        $this->getJson('/api/sales/ar/aging', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.total', 100);

        $this->postJson('/api/sales/customer-deposits/'.$deposit->id.'/allocate-to-invoice/'.$invoice['id'], [
            'allocated_amount' => 30,
            'allocation_date' => '2026-05-20',
        ], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/sales/ar/customer-summary', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.official_ar_balance', 70)
            ->assertJsonPath('data.0.unapplied_deposit_total', 0)
            ->assertJsonPath('data.0.net_customer_exposure', 70);

        $this->getJson('/api/sales/ar/aging', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.total', 70);
    }

    public function test_void_documents_are_excluded_and_tenants_are_isolated(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $invoice = $this->postedInvoice($ctx, 100);
        SalesInvoice::query()->find($invoice['id'])->update(['status' => 'void', 'voided_at' => now()]);

        $this->getJson('/api/sales/ar/customer-summary', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');

        $ctxB = $this->setUpTenant();
        $this->getJson('/api/sales/ar/customer-summary', $ctxB['headers'])
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_subsidiary_reconciles_to_gl_and_detects_mismatch(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx, 200);
        $this->postReceipt($ctx, $cash, $invoice, 50);

        $this->getJson('/api/sales/ar/reconciliation', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_reconciled', true)
            ->assertJsonPath('data.subsidiary_balance', 150)
            ->assertJsonPath('data.gl_ar_balance', 150);

        JournalEntryLine::query()->where('description', 'Accounts Receivable')->latest('id')->first()->update(['credit' => 40]);

        $this->getJson('/api/sales/ar/reconciliation', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_reconciled', false);
    }

    public function test_reconciliation_uses_invoice_receivable_snapshots_across_multiple_ar_accounts(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $arA = $this->account('1110', 'AR Customer A', 'asset', 'debit');
        $arB = $this->account('1120', 'AR Customer B', 'asset', 'debit');
        $customerA = $this->createCustomer(['name' => 'Customer AR A']);
        $customerB = $this->createCustomer(['name' => 'Customer AR B']);

        $invoiceA = $this->postJson('/api/sales/invoices', [
            'customer_id' => $customerA,
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-06-20',
            'lines' => [['description' => 'Service A', 'quantity' => 1, 'unit_price' => 100]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        SalesInvoice::query()->whereKey($invoiceA['id'])->update(['ar_account_id' => $arA]);
        $this->patchJson('/api/sales/invoices/'.$invoiceA['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ar_account_id', $arA);

        $invoiceB = $this->postJson('/api/sales/invoices', [
            'customer_id' => $customerB,
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-06-20',
            'lines' => [['description' => 'Service B', 'quantity' => 1, 'unit_price' => 200]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        SalesInvoice::query()->whereKey($invoiceB['id'])->update(['ar_account_id' => $arB]);
        $this->patchJson('/api/sales/invoices/'.$invoiceB['id'].'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.ar_account_id', $arB);

        $this->postReceipt($ctx, $cash, SalesInvoice::query()->with('lines')->findOrFail($invoiceA['id'])->toArray(), 40);

        $this->getJson('/api/sales/ar/reconciliation', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.is_reconciled', true)
            ->assertJsonPath('data.subsidiary_balance', 260)
            ->assertJsonPath('data.gl_ar_balance', 260);

        $this->getJson('/api/sales/ar/open-invoices', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.0.ar_account_id', $arA)
            ->assertJsonPath('data.1.ar_account_id', $arB);
    }

    public function test_invoice_ledger_respects_end_date_cutoff_filter(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx, 200);
        $this->postReceipt($ctx, $cash, $invoice, 50, '2026-05-21');
        $this->postReturn($ctx, $invoice, 25, '2026-05-22');

        $this->getJson('/api/sales/ar/invoices/'.$invoice['id'].'/ledger?end_date=2026-05-21', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonCount(2, 'data.movements')
            ->assertJsonPath('data.movements.1.balance', 150);
    }

    public function test_reconciliation_cutoff_stays_consistent_on_boundary_date(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $this->postedInvoice($ctx, 200);

        // A document dated exactly on the cutoff must be counted by both the
        // subledger (invoice_date) and the GL (journal_date) sides so the two
        // balances stay reconciled instead of showing a phantom difference.
        $this->getJson('/api/sales/ar/reconciliation?as_of_date=2026-05-20', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.subsidiary_balance', 200)
            ->assertJsonPath('data.gl_ar_balance', 200)
            ->assertJsonPath('data.difference', 0)
            ->assertJsonPath('data.is_reconciled', true);
    }

    private function postedInvoice(array $ctx, float $amount): array
    {
        $invoice = $this->postJson('/api/sales/invoices', [
            'customer_id' => $this->createCustomer(),
            'invoice_date' => '2026-05-20',
            'due_date' => '2026-06-20',
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        return SalesInvoice::query()->with('lines')->find($invoice['id'])->toArray();
    }

    private function postReceipt(array $ctx, int $cash, array $invoice, float $amount, string $date = '2026-05-20'): void
    {
        $receipt = $this->postJson('/api/sales/receipts', [
            'receipt_date' => $date,
            'customer_id' => $invoice['customer_id'],
            'sales_invoice_id' => $invoice['id'],
            'cash_bank_account_id' => $cash,
            'amount' => $amount,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertStatus(200);
    }

    private function allocateDeposit(array $ctx, int $cash, array $invoice, float $amount): void
    {
        $deposit = CustomerDeposit::query()->create([
            'deposit_number' => 'CD-AR-1',
            'deposit_date' => '2026-05-20',
            'customer_id' => $invoice['customer_id'],
            'cash_bank_account_id' => $cash,
            'amount' => $amount,
            'remaining_amount' => $amount,
            'allocated_amount' => 0,
            'status' => 'posted',
            'posted_at' => now(),
        ]);

        $this->postJson('/api/sales/customer-deposits/'.$deposit->id.'/allocate-to-invoice/'.$invoice['id'], [
            'amount' => $amount,
        ], $ctx['headers'])->assertStatus(200);

        $this->assertSame(1, CustomerDepositAllocation::query()->count());
    }

    private function postReturn(array $ctx, array $invoice, float $amount, string $date = '2026-05-20'): void
    {
        $return = $this->postJson('/api/sales/returns', [
            'return_date' => $date,
            'customer_id' => $invoice['customer_id'],
            'sales_invoice_id' => $invoice['id'],
            'lines' => [[
                'sales_invoice_line_id' => $invoice['lines'][0]['id'],
                'description' => 'Return',
                'quantity' => 0.125,
                'unit_price' => 200,
                'line_total' => $amount,
            ]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/returns/'.$return['id'].'/post', [], $ctx['headers'])->assertStatus(200);
    }

    private function seedMappings(): int
    {
        $cash = $this->account('1000', 'Cash', 'asset', 'debit', true);
        $ar = $this->account('1100', 'AR', 'asset', 'debit');
        $revenue = $this->account('4100', 'Revenue', 'revenue', 'credit');
        $deposit = $this->account('2200', 'Customer Deposit', 'liability', 'credit');
        $salesReturn = $this->account('4200', 'Sales Return', 'revenue', 'debit');
        foreach (['sales.accounts_receivable' => $ar, 'sales.revenue' => $revenue, 'sales.customer_deposit' => $deposit, 'sales.return' => $salesReturn] as $key => $id) {
            AccountMapping::query()->create(['mapping_key' => $key, 'module' => 'sales', 'account_id' => $id, 'is_required' => true, 'is_active' => true]);
        }

        return $cash;
    }

    private function account(string $code, string $name, string $type, string $normal, bool $cash = false): int
    {
        return (int) ChartOfAccount::query()->create(['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_cash_bank' => $cash, 'is_active' => true])->id;
    }
}
