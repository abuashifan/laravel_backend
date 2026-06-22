<?php

namespace Tests\Feature\CashBank;

use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use Tests\Feature\Journal\JournalTestCase;

class BankTransferTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_bank_transfers(): void
    {
        auth()->logout();
        $this->getJson('/api/cash-bank/bank-transfers')->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'finance');
        $this->getJson('/api/cash-bank/bank-transfers')->assertStatus(422);
    }

    public function test_can_create_and_post_bank_transfer_and_journal_is_created(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $fromId = (int) $ctx['accounts']['debit'];
        $to = ChartOfAccount::query()->create([
            'account_code' => '1010',
            'account_name' => 'Bank',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => true,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $payload = [
            'transfer_date' => '2026-01-12',
            'from_cash_bank_account_id' => $fromId,
            'to_cash_bank_account_id' => $to->id,
            'amount' => 3000,
            'notes' => 'Move cash to bank',
        ];

        $res = $this->postJson('/api/cash-bank/bank-transfers', $payload, $ctx['headers']);
        $res->assertStatus(201);
        $id = (int) $res->json('data.id');

        $res2 = $this->patchJson('/api/cash-bank/bank-transfers/'.$id.'/post', [], $ctx['headers']);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.status', 'posted');
        $journalEntryId = (int) $res2->json('data.journal_entry_id');
        $this->assertGreaterThan(0, $journalEntryId);

        $je = JournalEntry::query()->with('lines')->findOrFail($journalEntryId);
        $this->assertSame('bank_transfer', $je->source_type);
        $this->assertSame('cash_bank', $je->source_module);

        $lines = $je->lines->sortBy('line_order')->values();
        $this->assertCount(2, $lines);
        $this->assertSame((int) $to->id, (int) $lines[0]->account_id);
        $this->assertSame(3000.0, (float) $lines[0]->debit);
        $this->assertSame(0.0, (float) $lines[0]->credit);
        $this->assertSame($fromId, (int) $lines[1]->account_id);
        $this->assertSame(0.0, (float) $lines[1]->debit);
        $this->assertSame(3000.0, (float) $lines[1]->credit);
    }

    public function test_same_account_is_rejected_and_draft_can_be_updated(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $fromId = (int) $ctx['accounts']['debit'];
        $to = ChartOfAccount::query()->create([
            'account_code' => '1020',
            'account_name' => 'Bank Two',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => true,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $this->postJson('/api/cash-bank/bank-transfers', [
            'transfer_date' => '2026-01-12',
            'from_cash_bank_account_id' => $fromId,
            'to_cash_bank_account_id' => $fromId,
            'amount' => 3000,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('to_cash_bank_account_id');

        $created = $this->postJson('/api/cash-bank/bank-transfers', [
            'transfer_date' => '2026-01-12',
            'from_cash_bank_account_id' => $fromId,
            'to_cash_bank_account_id' => $to->id,
            'amount' => 3000,
        ], $ctx['headers'])->assertCreated();

        $this->patchJson('/api/cash-bank/bank-transfers/'.$created->json('data.id'), [
            'transfer_date' => '2026-01-13',
            'from_cash_bank_account_id' => $fromId,
            'to_cash_bank_account_id' => $to->id,
            'amount' => 3500,
        ], $ctx['headers'])
            ->assertOk()
            ->assertJsonPath('data.amount', 3500);
    }
}
