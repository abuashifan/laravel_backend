<?php

namespace Tests\Feature\CashBank;

use App\Models\Tenant\ChartOfAccount;
use Tests\Feature\Journal\JournalTestCase;

class CashBankIntegrationTest extends JournalTestCase
{
    public function test_cash_bank_flow_receipt_payment_transfer_statement_and_reconciliation_lines_consistent(): void
    {
        $ctx = $this->setUpTenant(role: 'finance');

        $cashId = (int) $ctx['accounts']['debit'];   // is_cash_bank=true
        $revenueId = (int) $ctx['accounts']['credit']; // revenue

        $bank = ChartOfAccount::query()->create([
            'account_code' => '1010',
            'account_name' => 'Bank',
            'account_type' => 'asset',
            'normal_balance' => 'debit',
            'is_cash_bank' => true,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        $expense = ChartOfAccount::query()->create([
            'account_code' => '5000',
            'account_name' => 'Expense',
            'account_type' => 'expense',
            'normal_balance' => 'debit',
            'is_cash_bank' => false,
            'is_active' => true,
            'is_system_default' => false,
        ]);

        // 1) Cash In: +1000 to cash
        $r1 = $this->postJson('/api/cash-bank/cash-receipts', [
            'receipt_date' => '2026-01-10',
            'cash_bank_account_id' => $cashId,
            'amount' => 1000,
            'lines' => [
                ['account_id' => $revenueId, 'amount' => 1000, 'description' => 'Income', 'line_order' => 1],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $receiptId = (int) $r1->json('data.id');
        $this->patchJson('/api/cash-bank/cash-receipts/'.$receiptId.'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        // 2) Cash Out: -200 from cash
        $p1 = $this->postJson('/api/cash-bank/cash-payments', [
            'payment_date' => '2026-01-11',
            'cash_bank_account_id' => $cashId,
            'amount' => 200,
            'lines' => [
                ['account_id' => $expense->id, 'amount' => 200, 'description' => 'Expense', 'line_order' => 1],
            ],
        ], $ctx['headers'])->assertStatus(201);
        $paymentId = (int) $p1->json('data.id');
        $this->patchJson('/api/cash-bank/cash-payments/'.$paymentId.'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        // 3) Transfer: -300 cash, +300 bank
        $t1 = $this->postJson('/api/cash-bank/bank-transfers', [
            'transfer_date' => '2026-01-12',
            'from_cash_bank_account_id' => $cashId,
            'to_cash_bank_account_id' => $bank->id,
            'amount' => 300,
        ], $ctx['headers'])->assertStatus(201);
        $transferId = (int) $t1->json('data.id');
        $this->patchJson('/api/cash-bank/bank-transfers/'.$transferId.'/post', [], $ctx['headers'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'posted');

        // 4) Account statements
        $cashStmt = $this->getJson('/api/cash-bank/reports/account-statement?cash_bank_account_id='.$cashId.'&start_date=2026-01-01&end_date=2026-01-31', $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame(0.0, (float) $cashStmt->json('data.opening_balance'));
        $this->assertSame(500.0, (float) $cashStmt->json('data.ending_balance')); // 1000 - 200 - 300
        $this->assertCount(3, (array) $cashStmt->json('data.lines'));

        $bankStmt = $this->getJson('/api/cash-bank/reports/account-statement?cash_bank_account_id='.$bank->id.'&start_date=2026-01-01&end_date=2026-01-31', $ctx['headers'])
            ->assertStatus(200);
        $this->assertSame(0.0, (float) $bankStmt->json('data.opening_balance'));
        $this->assertSame(300.0, (float) $bankStmt->json('data.ending_balance'));
        $this->assertCount(1, (array) $bankStmt->json('data.lines'));

        // 5) Bank reconciliation (lines generated from posted cash/bank journals)
        $rec = $this->postJson('/api/cash-bank/bank-reconciliations', [
            'cash_bank_account_id' => $cashId,
            'statement_start_date' => '2026-01-01',
            'statement_end_date' => '2026-01-31',
            'statement_opening_balance' => 0,
            'statement_ending_balance' => 500,
        ], $ctx['headers'])->assertStatus(201);

        $this->assertCount(3, (array) $rec->json('data.lines'));
    }
}

