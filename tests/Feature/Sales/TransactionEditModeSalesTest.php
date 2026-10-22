<?php

namespace Tests\Feature\Sales;

use App\Modules\CashBank\Models\CashReceipt;
use App\Modules\Journal\Models\JournalEntry;
use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\Sales\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesReceipt;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyAccountingSetting;
use Illuminate\Support\Facades\DB;

/**
 * Mode ubah transaksi lock/open untuk faktur penjualan, penerimaan pelanggan,
 * kas masuk, dan jurnal manual.
 *
 * - lock (bawaan): hanya draft yang boleh diedit/dihapus.
 * - open: semua status (kecuali void) boleh, selama tidak tertaut transaksi lain.
 */
class TransactionEditModeSalesTest extends SalesTestCase
{
    public function test_settings_default_to_lock_and_can_switch_to_open(): void
    {
        $ctx = $this->setUpTenant();

        $this->getJson('/api/settings/company/workflow', $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.transaction_edit_mode', 'lock');

        $this->patchJson('/api/settings/company/accounting', ['transaction_edit_mode' => 'open'], $ctx['headers'])->assertOk();
        $this->assertSame('open', CompanyAccountingSetting::query()->where('company_id', $ctx['company']->id)->value('transaction_edit_mode'));

        $this->patchJson('/api/settings/company/accounting', ['transaction_edit_mode' => 'bebas'], $ctx['headers'])->assertStatus(422);
    }

    public function test_lock_mode_allows_draft_changes_only(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();

        $draft = $this->draftInvoice($ctx);
        $this->patchJson('/api/sales/invoices/'.$draft['id'], $this->invoicePayload($draft['customer_id'], 150), $ctx['headers'])->assertOk();
        $this->deleteJson('/api/sales/invoices/'.$draft['id'], [], $ctx['headers'])->assertOk();
        $this->assertNull(SalesInvoice::withTrashed()->find($draft['id']), 'Draft dihapus permanen.');

        $posted = $this->postedInvoice($ctx);
        $this->patchJson('/api/sales/invoices/'.$posted['id'], $this->invoicePayload($posted['customer_id'], 150), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_LOCKED');
        $this->deleteJson('/api/sales/invoices/'.$posted['id'], ['reason' => 'Salah input'], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_LOCKED');
    }

    public function test_open_mode_edits_posted_invoice_by_reversing_and_reposting(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $this->setMode($ctx['company'], 'open');

        $invoice = $this->postedInvoice($ctx);
        $oldJournalId = (int) SalesInvoice::query()->find($invoice['id'])->journal_entry_id;

        $updated = $this->patchJson('/api/sales/invoices/'.$invoice['id'], $this->invoicePayload($invoice['customer_id'], 250), $ctx['headers'])
            ->assertOk()
            ->json('data');

        $this->assertSame('posted', $updated['status']);
        $this->assertSame($invoice['invoice_number'], $updated['invoice_number']);
        $this->assertEquals(250, (float) $updated['grand_total']);
        $this->assertEquals(250, (float) $updated['balance_due']);
        $this->assertGreaterThan((int) $invoice['revision_no'], (int) $updated['revision_no']);

        $this->assertSame('void', JournalEntry::query()->find($oldJournalId)->status, 'Jurnal lama tetap ada sebagai jejak, berstatus void.');
        $newJournal = JournalEntry::query()->find((int) $updated['journal_entry_id']);
        $this->assertSame('posted', $newJournal->status);
        $this->assertEquals(250, (float) $newJournal->lines()->sum('debit'));
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'sales_invoice')->where('source_id', $invoice['id'])->where('status', 'posted')->count());
    }

    public function test_open_mode_deletes_posted_invoice_as_void_and_hides_it(): void
    {
        $ctx = $this->setUpTenant();
        $this->seedMappings();
        $this->setMode($ctx['company'], 'open');

        $invoice = $this->postedInvoice($ctx);
        $this->deleteJson('/api/sales/invoices/'.$invoice['id'], ['reason' => 'Faktur ganda'], $ctx['headers'])->assertOk();

        $this->assertNull(SalesInvoice::query()->find($invoice['id']));
        $trashed = SalesInvoice::withTrashed()->find($invoice['id']);
        $this->assertSame('void', $trashed->status);
        $this->assertSame('Faktur ganda', $trashed->delete_reason);
        $this->assertSame(0, JournalEntry::query()->where('source_type', 'sales_invoice')->where('source_id', $invoice['id'])->where('status', 'posted')->count());
        $this->getJson('/api/sales/invoices/'.$invoice['id'], $ctx['headers'])->assertNotFound();
    }

    public function test_invoice_with_receipt_cannot_be_edited_or_deleted_even_in_open_mode(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $this->setMode($ctx['company'], 'open');

        $invoice = $this->postedInvoice($ctx);
        $receipt = $this->postJson('/api/sales/receipts', $this->receiptPayload($cash, $invoice, 40), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertOk();

        $edit = $this->patchJson('/api/sales/invoices/'.$invoice['id'], $this->invoicePayload($invoice['customer_id'], 250), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_HAS_DEPENDENCY');
        $this->assertStringContainsString($receipt['receipt_number'], (string) $edit->json('message'));

        $this->deleteJson('/api/sales/invoices/'.$invoice['id'], ['reason' => 'Coba hapus'], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_HAS_DEPENDENCY');
    }

    public function test_open_mode_edits_posted_receipt_and_rebalances_invoice(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $this->setMode($ctx['company'], 'open');

        $invoice = $this->postedInvoice($ctx);
        $receipt = $this->postJson('/api/sales/receipts', $this->receiptPayload($cash, $invoice, 40), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/sales/receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertOk();
        $this->assertEquals(60, (float) SalesInvoice::query()->find($invoice['id'])->balance_due);

        $this->patchJson('/api/sales/receipts/'.$receipt['id'], $this->receiptPayload($cash, $invoice, 100), $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');

        $fresh = SalesInvoice::query()->find($invoice['id']);
        $this->assertEquals(100, (float) $fresh->paid_amount);
        $this->assertEquals(0, (float) $fresh->balance_due);
        $this->assertSame('paid', $fresh->status);
        $this->assertSame(1, JournalEntry::query()->where('source_type', 'sales_receipt')->where('source_id', $receipt['id'])->where('status', 'posted')->count());

        $this->deleteJson('/api/sales/receipts/'.$receipt['id'], ['reason' => 'Batal'], $ctx['headers'])->assertOk();
        $this->assertNull(SalesReceipt::query()->find($receipt['id']));
        $this->assertEquals(100, (float) SalesInvoice::query()->find($invoice['id'])->balance_due);
    }

    public function test_draft_receipt_can_be_edited_in_lock_mode(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $invoice = $this->postedInvoice($ctx);
        $receipt = $this->postJson('/api/sales/receipts', $this->receiptPayload($cash, $invoice, 40), $ctx['headers'])->assertCreated()->json('data');

        $this->patchJson('/api/sales/receipts/'.$receipt['id'], $this->receiptPayload($cash, $invoice, 70), $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');
        $this->assertEquals(70, (float) SalesReceipt::query()->find($receipt['id'])->lines()->sum('amount'));
    }

    public function test_cash_receipt_edit_and_reconciled_journal_blocks_changes(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $income = $this->account('4900', 'Other Income', 'revenue', 'credit');
        $this->setMode($ctx['company'], 'open');

        $payload = fn (float $amount): array => [
            'receipt_date' => '2026-05-20',
            'cash_bank_account_id' => $cash,
            'amount' => $amount,
            'lines' => [['account_id' => $income, 'amount' => $amount, 'description' => 'Pendapatan lain']],
        ];

        $receipt = $this->postJson('/api/cash-bank/cash-receipts', $payload(100), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/cash-bank/cash-receipts/'.$receipt['id'].'/post', [], $ctx['headers'])->assertOk();

        $this->patchJson('/api/cash-bank/cash-receipts/'.$receipt['id'], $payload(120), $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.status', 'posted');
        $this->assertSame($receipt['receipt_number'], CashReceipt::query()->find($receipt['id'])->receipt_number);
        $journalId = (int) CashReceipt::query()->find($receipt['id'])->journal_entry_id;
        $this->assertEquals(120, (float) JournalEntry::query()->find($journalId)->lines()->sum('debit'));

        // Rekonsiliasi bank yang sudah dicentang mengunci transaksinya.
        $recId = DB::connection('tenant')->table('bank_reconciliations')->insertGetId([
            'reconciliation_number' => 'REC-001', 'cash_bank_account_id' => $cash,
            'statement_start_date' => '2026-05-01', 'statement_end_date' => '2026-05-31',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::connection('tenant')->table('bank_reconciliation_lines')->insert([
            'bank_reconciliation_id' => $recId, 'journal_entry_id' => $journalId,
            'journal_entry_line_id' => (int) JournalEntry::query()->find($journalId)->lines()->value('id'),
            'journal_date' => '2026-05-20', 'journal_number' => 'J', 'debit' => 120, 'credit' => 0,
            'is_cleared' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $blocked = $this->deleteJson('/api/cash-bank/cash-receipts/'.$receipt['id'], ['reason' => 'Hapus'], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_HAS_DEPENDENCY');
        $this->assertStringContainsString('REC-001', (string) $blocked->json('message'));
    }

    public function test_manual_journal_follows_lock_and_open_modes(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $income = $this->account('4900', 'Other Income', 'revenue', 'credit');

        $payload = fn (float $amount, string $date = '2026-05-20'): array => [
            'journal_date' => $date,
            'description' => 'Jurnal manual',
            'edit_reason' => 'Koreksi nilai',
            'lines' => [
                ['account_id' => $cash, 'debit' => $amount, 'credit' => 0, 'line_order' => 1],
                ['account_id' => $income, 'debit' => 0, 'credit' => $amount, 'line_order' => 2],
            ],
        ];

        $draft = $this->postJson('/api/journals', $payload(50), $ctx['headers'])->assertCreated()->json('data');
        $this->deleteJson('/api/journals/'.$draft['id'], [], $ctx['headers'])->assertOk();
        $this->assertNull(JournalEntry::withTrashed()->find($draft['id']));

        $journal = $this->postJson('/api/journals', $payload(75), $ctx['headers'])->assertCreated()->json('data');
        $this->postJson('/api/journals/'.$journal['id'].'/post', [], $ctx['headers'])->assertOk();

        $this->patchJson('/api/journals/'.$journal['id'], $payload(80), $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_LOCKED');
        $this->deleteJson('/api/journals/'.$journal['id'], ['reason' => 'Salah'], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'TRANSACTION_LOCKED');

        $this->setMode($ctx['company'], 'open');
        $this->patchJson('/api/journals/'.$journal['id'], $payload(80), $ctx['headers'])->assertOk();
        $this->deleteJson('/api/journals/'.$journal['id'], ['reason' => 'Salah'], $ctx['headers'])->assertOk();

        $this->assertNull(JournalEntry::query()->find($journal['id']));
        $this->assertSame('void', JournalEntry::withTrashed()->find($journal['id'])->status);
    }

    private function setMode(Company $company, string $mode): void
    {
        CompanyAccountingSetting::query()->where('company_id', $company->id)->update(['transaction_edit_mode' => $mode]);
    }

    private function invoicePayload(int $customerId, float $price): array
    {
        return ['customer_id' => $customerId, 'invoice_date' => '2026-05-20', 'lines' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => $price]]];
    }

    private function draftInvoice(array $ctx): array
    {
        return $this->postJson('/api/sales/invoices', $this->invoicePayload($this->createCustomer(), 100), $ctx['headers'])->assertCreated()->json('data');
    }

    private function postedInvoice(array $ctx): array
    {
        $invoice = $this->draftInvoice($ctx);
        $this->patchJson('/api/sales/invoices/'.$invoice['id'].'/post', [], $ctx['headers'])->assertOk();

        return SalesInvoice::query()->find($invoice['id'])->toArray();
    }

    private function receiptPayload(int $cash, array $invoice, float $amount): array
    {
        return ['receipt_date' => '2026-05-20', 'customer_id' => $invoice['customer_id'], 'sales_invoice_id' => $invoice['id'], 'cash_bank_account_id' => $cash, 'amount' => $amount];
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
