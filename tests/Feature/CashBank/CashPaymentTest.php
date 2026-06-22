<?php

namespace Tests\Feature\CashBank;

use App\Models\Tenant\ChartOfAccount;
use App\Models\Tenant\JournalEntry;
use Tests\Feature\Journal\JournalTestCase;

class CashPaymentTest extends JournalTestCase
{
    public function test_unauthenticated_cannot_access_cash_payments(): void
    {
        auth()->logout();
        $this->getJson('/api/cash-bank/cash-payments')->assertStatus(401);
    }

    public function test_missing_x_company_id_rejected(): void
    {
        $this->setUpTenant(role: 'finance');
        $this->getJson('/api/cash-bank/cash-payments')->assertStatus(422);
    }

    public function test_requires_permission(): void
    {
        $ctx = $this->setUpTenant(role: 'noaccess');
        $this->getJson('/api/cash-bank/cash-payments', $ctx['headers'])->assertStatus(403);
    }

    public function test_can_create_and_post_cash_payment_and_journal_is_created(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit'];

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $payload = [
            'payment_date' => '2026-01-11',
            'cash_bank_account_id' => $cashId,
            'amount' => 2000,
            'notes' => 'Cash out',
            'lines' => [
                ['account_id' => $expense->id, 'amount' => 2000, 'description' => 'Expense', 'line_order' => 1],
            ],
        ];

        $res = $this->postJson('/api/cash-bank/cash-payments', $payload, $ctx['headers']);
        $res->assertStatus(201);
        $id = (int) $res->json('data.id');
        $this->assertNotSame(0, $id);

        $res2 = $this->patchJson('/api/cash-bank/cash-payments/'.$id.'/post', [], $ctx['headers']);
        $res2->assertStatus(200);
        $res2->assertJsonPath('data.status', 'posted');
        $journalEntryId = (int) $res2->json('data.journal_entry_id');
        $this->assertGreaterThan(0, $journalEntryId);

        $je = JournalEntry::query()->with('lines')->findOrFail($journalEntryId);
        $this->assertSame('cash_payment', $je->source_type);
        $this->assertSame('cash_bank', $je->source_module);

        $lines = $je->lines->sortBy('line_order')->values();
        $this->assertCount(2, $lines);
        $this->assertSame((int) $expense->id, (int) $lines[0]->account_id);
        $this->assertSame(2000.0, (float) $lines[0]->debit);
        $this->assertSame(0.0, (float) $lines[0]->credit);
        $this->assertSame($cashId, (int) $lines[1]->account_id);
        $this->assertSame(0.0, (float) $lines[1]->debit);
        $this->assertSame(2000.0, (float) $lines[1]->credit);
    }

    public function test_lines_are_required_and_amount_must_match(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');
        $cashId = (int) $ctx['accounts']['debit'];
        $expense = ChartOfAccount::query()->create([
            'account_code' => '5100',
            'account_name' => 'Other Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $this->postJson('/api/cash-bank/cash-payments', [
            'payment_date' => '2026-01-11',
            'cash_bank_account_id' => $cashId,
            'amount' => 1000,
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lines');

        $this->postJson('/api/cash-bank/cash-payments', [
            'payment_date' => '2026-01-11',
            'cash_bank_account_id' => $cashId,
            'amount' => 1000,
            'lines' => [['account_id' => $expense->id, 'amount' => 900]],
        ], $ctx['headers'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'AMOUNT_MISMATCH');
    }
}
