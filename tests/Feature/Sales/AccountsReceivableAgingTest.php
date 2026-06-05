<?php

namespace Tests\Feature\Sales;

use App\Models\Tenant\AccountMapping;
use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\SalesInvoice;

class AccountsReceivableAgingTest extends SalesTestCase
{
    public function test_aging_buckets_and_customer_filter(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $customerId = $this->createCustomer();
        $this->postedInvoice($ctx, $customerId, '2026-05-20', '2026-07-01', 10);
        $this->postedInvoice($ctx, $customerId, '2026-05-20', '2026-06-10', 20);
        $this->postedInvoice($ctx, $customerId, '2026-01-01', '2026-05-10', 30);
        $this->postedInvoice($ctx, $customerId, '2026-01-01', '2026-04-10', 40);
        $this->postedInvoice($ctx, $customerId, '2026-01-01', '2026-03-01', 50);

        $this->getJson('/api/sales/ar/aging?as_of_date=2026-07-01', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.buckets.current', 10)
            ->assertJsonPath('data.buckets.1_30', 20)
            ->assertJsonPath('data.buckets.31_60', 30)
            ->assertJsonPath('data.buckets.61_90', 40)
            ->assertJsonPath('data.buckets.over_90', 50)
            ->assertJsonPath('data.total', 150);

        $partial = $this->postedInvoice($ctx, $customerId, '2026-05-20', '2026-06-10', 100);
        $receipt = $this->postJson('/api/sales/receipts', [
            'receipt_date' => '2026-05-20',
            'customer_id' => $customerId,
            'sales_invoice_id' => $partial['id'],
            'cash_bank_account_id' => $cash,
            'amount' => 25,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/sales/ar/aging?as_of_date=2026-07-01&customer_id='.$customerId, $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.buckets.1_30', 95)
            ->assertJsonPath('data.total', 225);
    }

    public function test_paid_invoice_is_excluded_from_aging(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx, $this->createCustomer(), '2026-05-20', '2026-06-10', 100);
        $receipt = $this->postJson('/api/sales/receipts', [
            'receipt_date' => '2026-05-20',
            'customer_id' => $invoice['customer_id'],
            'sales_invoice_id' => $invoice['id'],
            'cash_bank_account_id' => $cash,
            'amount' => 100,
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        $this->getJson('/api/sales/ar/aging?as_of_date=2026-07-01', $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.total', 0);
    }

    private function postedInvoice(array $ctx, int $customerId, string $invoiceDate, string $dueDate, float $amount): array
    {
        $invoice = $this->postJson('/api/sales/invoices', [
            'customer_id' => $customerId,
            'invoice_date' => $invoiceDate,
            'due_date' => $dueDate,
            'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $amount]],
        ], $ctx['headers'])->assertStatus(201)->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertStatus(200);

        return SalesInvoice::query()->find($invoice['id'])->toArray();
    }

    private function seedMappings(): int
    {
        $cash = $this->account('1000', 'Cash', 'asset', 'debit', true);
        $ar = $this->account('1100', 'AR', 'asset', 'debit');
        $revenue = $this->account('4100', 'Revenue', 'revenue', 'credit');
        foreach (['sales.accounts_receivable' => $ar, 'sales.revenue' => $revenue] as $key => $id) {
            AccountMapping::query()->create(['mapping_key' => $key, 'module' => 'sales', 'account_id' => $id, 'is_required' => true, 'is_active' => true]);
        }

        return $cash;
    }

    private function account(string $code, string $name, string $type, string $normal, bool $cash = false): int
    {
        return (int) ChartOfAccount::query()->create(['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_cash_bank' => $cash, 'is_active' => true])->id;
    }
}
