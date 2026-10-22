<?php

namespace Tests\Feature\Sales;

use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Sales\Models\CustomerDeposit;
use App\Modules\Sales\Models\SalesInvoice;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyAccountingSetting;

/**
 * Mode lock/open tahap 2 -- deposit pelanggan.
 */
class TransactionEditModeStage2SalesTest extends SalesTestCase
{
    public function test_lock_mode_blocks_posted_deposit_but_allows_draft_changes(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();

        $draft = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, 100), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$draft['id'], $this->depositPayload($cash, 150, $draft['customer_id']), $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.remaining_amount', 150);
        $this->deleteJson('/api/sales/customer-deposits/'.$draft['id'], [], $ctx['headers'])->assertOk();
        $this->assertNull(CustomerDeposit::withTrashed()->find($draft['id']));

        $posted = $this->postedDeposit($ctx, $cash);
        $this->patchJson('/api/sales/customer-deposits/'.$posted['id'], $this->depositPayload($cash, 200, $posted['customer_id']), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_LOCKED');
    }

    public function test_open_mode_edits_posted_deposit_and_reposts_journal(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $this->setMode($ctx['company'], 'open');

        $deposit = $this->postedDeposit($ctx, $cash);
        $updated = $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'], $this->depositPayload($cash, 250, $deposit['customer_id']), $ctx['headers'])
            ->assertOk()
            ->json('data');

        $this->assertSame('posted', $updated['status']);
        $this->assertSame($deposit['deposit_number'], $updated['deposit_number']);
        $this->assertEquals(250, (float) $updated['remaining_amount']);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'customer_deposit')->where('source_id', $deposit['id'])->where('status', 'posted')->count());
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'customer_deposit')->where('source_id', $deposit['id'])->where('status', 'void')->count());

        $this->deleteJson('/api/sales/customer-deposits/'.$deposit['id'], ['reason' => 'Salah input'], $ctx['headers'])->assertOk();
        $this->assertNull(CustomerDeposit::query()->find($deposit['id']));
        $this->assertSame('void', CustomerDeposit::withTrashed()->find($deposit['id'])->status);
    }

    public function test_allocated_deposit_is_locked_even_in_open_mode(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $this->setMode($ctx['company'], 'open');

        $deposit = $this->postedDeposit($ctx, $cash);
        $invoice = $this->postJson('/api/sales/invoices', ['customer_id' => $deposit['customer_id'], 'invoice_date' => '2026-05-20', 'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 100]]], $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertOk();
        $this->postJson('/api/sales/customer-deposits/'.$deposit['id'].'/allocate-to-invoice/'.$invoice['id'], [
            'allocated_amount' => 40,
            'allocation_date' => '2026-05-20',
        ], $ctx['headers'])->assertOk();

        $blocked = $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'], $this->depositPayload($cash, 300, $deposit['customer_id']), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_HAS_DEPENDENCY');
        $this->assertStringContainsString((string) SalesInvoice::query()->find($invoice['id'])->invoice_number, (string) $blocked->json('message'));
    }

    private function postedDeposit(array $ctx, int $cash): array
    {
        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, 100), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertOk();

        return CustomerDeposit::query()->find($deposit['id'])->toArray();
    }

    private function depositPayload(int $cash, float $amount, ?int $customerId = null): array
    {
        return ['deposit_date' => '2026-05-20', 'customer_id' => $customerId ?? $this->createCustomer(), 'cash_bank_account_id' => $cash, 'amount' => $amount];
    }

    private function setMode(Company $company, string $mode): void
    {
        CompanyAccountingSetting::query()->where('company_id', $company->id)->update(['transaction_edit_mode' => $mode]);
    }

    private function seedMappings(): int
    {
        $cash = $this->account('1000', 'Cash', 'asset', 'debit', true);
        $ar = $this->account('1100', 'AR', 'asset', 'debit');
        $revenue = $this->account('4100', 'Revenue', 'revenue', 'credit');
        $deposit = $this->account('2200', 'Deposit', 'liability', 'credit');
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
