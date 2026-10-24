<?php

namespace Tests\Feature\Sales;

use App\Modules\MasterData\Models\AccountMapping;
use App\Modules\MasterData\Models\ChartOfAccount;
use App\Modules\MasterData\Models\Contact;
use App\Shared\Models\Company;
use App\Shared\Models\CompanyAccountingSetting;
use App\Shared\Models\TransactionRevision;

/**
 * Riwayat edit dokumen: dicatat diam-diam saat dokumen yang sudah tidak draft
 * diedit, dan hanya bisa dibaca peran dengan izin audit.document_history.view.
 */
class DocumentHistoryTest extends SalesTestCase
{
    public function test_editing_posted_document_records_before_and_after_with_labels(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $this->setMode($ctx['company'], 'open');

        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, 100), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertOk();

        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'], $this->depositPayload($cash, 250, $deposit['customer_id']), $ctx['headers'])->assertOk();

        $history = $this->getJson('/api/accounting/document-history/customer_deposit/'.$deposit['id'], $ctx['headers'])
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $history['entries']);
        $entry = $history['entries'][0];
        $this->assertEquals(100, (float) $entry['before']['header']['amount']);
        $this->assertEquals(250, (float) $entry['after']['header']['amount']);
        $this->assertNotNull($entry['edited_at']);
        $customerName = Contact::query()->find($deposit['customer_id'])->name;
        $this->assertSame($customerName, $history['labels']['contact'][(string) $deposit['customer_id']]);
    }

    public function test_draft_edits_and_unchanged_saves_are_not_recorded(): void
    {
        $ctx = $this->setUpTenant();
        $cash = $this->seedMappings();
        $this->setMode($ctx['company'], 'open');

        $deposit = $this->postJson('/api/sales/customer-deposits', $this->depositPayload($cash, 100), $ctx['headers'])->assertCreated()->json('data');
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'], $this->depositPayload($cash, 150, $deposit['customer_id']), $ctx['headers'])->assertOk();
        $this->assertSame(0, TransactionRevision::query()->where('source_type', 'customer_deposit')->count());

        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'].'/post', [], $ctx['headers'])->assertOk();
        $this->patchJson('/api/sales/customer-deposits/'.$deposit['id'], $this->depositPayload($cash, 150, $deposit['customer_id']), $ctx['headers'])->assertOk();
        $this->assertSame(0, TransactionRevision::query()->where('source_type', 'customer_deposit')->count());
    }

    public function test_history_requires_permission(): void
    {
        $ctx = $this->setUpTenant('sales');

        $this->getJson('/api/accounting/document-history/customer_deposit/1', $ctx['headers'])->assertForbidden();
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
        $deposit = $this->account('2200', 'Deposit', 'liability', 'credit');
        AccountMapping::query()->create(['mapping_key' => 'sales.customer_deposit', 'module' => 'sales', 'account_id' => $deposit, 'is_required' => true, 'is_active' => true]);

        return $cash;
    }

    private function account(string $code, string $name, string $type, string $normal, bool $cash = false): int
    {
        return (int) ChartOfAccount::query()->create(['account_code' => $code, 'account_name' => $name, 'account_type' => $type, 'normal_balance' => $normal, 'is_cash_bank' => $cash, 'is_active' => true])->id;
    }
}
