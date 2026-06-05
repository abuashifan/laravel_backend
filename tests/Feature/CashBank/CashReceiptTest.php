<?php

namespace Tests\Feature\CashBank;

use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use Tests\Feature\Journal\JournalTestCase;

class CashReceiptTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_cash_receipts(): void
    {
        auth()->logout();
        $this->getJson('/api/cash-bank/cash-receipts')->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'finance');
        $this->getJson('/api/cash-bank/cash-receipts')->assertStatus(422);
    }

    public function test_requires_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/cash-bank/cash-receipts', $ctx['headers'])->assertStatus(403);
    }

    public function test_can_create_and_post_cash_receipt_and_journal_is_created(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit'];
        $incomeId = (int) $ctx['accounts']['credit'];

        $payload = [
            'receipt_date' => '2026-01-10',
            'cash_bank_account_id' => $cashId,
            'amount' => 1000,
            'notes' => 'Cash in',
            'lines' => [
                ['account_id' => $incomeId, 'amount' => 1000, 'description' => 'Income', 'line_order' => 1],
            ],
        ];

        $res = $this->postJson('/api/cash-bank/cash-receipts', $payload, $ctx['headers']);
        $res->assertStatus(201);
        $id = (int) $res->json('data.id');
        $this->assertNotSame(0, $id);

        $res2 = $this->patchJson('/api/cash-bank/cash-receipts/'.$id.'/post', [], $ctx['headers']);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.status', 'posted');
        $journalEntryId = (int) $res2->json('data.journal_entry_id');
        $this->assertGreaterThan(0, $journalEntryId);

        $je = JournalEntry::query()->with('lines')->findOrFail($journalEntryId);
        $this->assertSame('cash_receipt', $je->source_type);
        $this->assertSame('cash_bank', $je->source_module);

        $lines = $je->lines->sortBy('line_order')->values();
        $this->assertCount(2, $lines);
        $this->assertSame($cashId, (int) $lines[0]->account_id);
        $this->assertSame(1000.0, (float) $lines[0]->debit);
        $this->assertSame(0.0, (float) $lines[0]->credit);
        $this->assertSame($incomeId, (int) $lines[1]->account_id);
        $this->assertSame(0.0, (float) $lines[1]->debit);
        $this->assertSame(1000.0, (float) $lines[1]->credit);
    }

    public function test_rejects_amount_mismatch(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit'];
        $incomeId = (int) $ctx['accounts']['credit'];

        $payload = [
            'receipt_date' => '2026-01-10',
            'cash_bank_account_id' => $cashId,
            'amount' => 999,
            'lines' => [
                ['account_id' => $incomeId, 'amount' => 1000, 'description' => 'Income', 'line_order' => 1],
            ],
        ];

        $res = $this->postJson('/api/cash-bank/cash-receipts', $payload, $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'AMOUNT_MISMATCH');
    }

    public function test_requires_cash_bank_account_marker(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $notCash = ChartOfAccount::query()->create([
            'account_code' => '1100',
            'account_name' => 'AR',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $payload = [
            'receipt_date' => '2026-01-10',
            'cash_bank_account_id' => $notCash->id,
            'amount' => 1000,
            'lines' => [
                ['account_id' => (int) $ctx['accounts']['credit'], 'amount' => 1000, 'description' => 'Income', 'line_order' => 1],
            ],
        ];

        $res = $this->postJson('/api/cash-bank/cash-receipts', $payload, $ctx['headers']);
        $res->assertStatus(422);
        $res->assertJsonPath('code', 'CASH_BANK_ACCOUNT_REQUIRED');
    }
}

