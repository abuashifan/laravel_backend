<?php

namespace Tests\Unit;

use App\Services\Journal\JournalValidationService;
use PHPUnit\Framework\TestCase;

class JournalValidationServiceTest extends TestCase
{
    public function test_balanced_lines_pass(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['account_id' => 1, 'debit' => 100, 'credit' => 0],
            ['account_id' => 2, 'debit' => 0, 'credit' => 100],
        ];

        $res = $svc->validateBalanced($lines);
        $this->assertTrue($res['valid']);
    }

    public function test_unbalanced_lines_fail(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['account_id' => 1, 'debit' => 100, 'credit' => 0],
            ['account_id' => 2, 'debit' => 0, 'credit' => 90],
        ];

        $res = $svc->validateBalanced($lines);
        $this->assertFalse($res['valid']);
    }

    public function test_less_than_two_lines_fail(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['account_id' => 1, 'debit' => 100, 'credit' => 0],
        ];

        $res = $svc->validateLines($lines, requireActiveAccounts: false);
        $this->assertFalse($res['valid']);
    }

    public function test_line_with_both_debit_and_credit_fails(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['account_id' => 1, 'debit' => 100, 'credit' => 10],
            ['account_id' => 2, 'debit' => 0, 'credit' => 110],
        ];

        $res = $svc->validateLines($lines, requireActiveAccounts: false);
        $this->assertFalse($res['valid']);
    }

    public function test_line_with_zero_debit_and_zero_credit_fails(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['account_id' => 1, 'debit' => 0, 'credit' => 0],
            ['account_id' => 2, 'debit' => 0, 'credit' => 0],
        ];

        $res = $svc->validateLines($lines, requireActiveAccounts: false);
        $this->assertFalse($res['valid']);
    }

    public function test_negative_debit_fails(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['account_id' => 1, 'debit' => -1, 'credit' => 0],
            ['account_id' => 2, 'debit' => 0, 'credit' => 1],
        ];

        $res = $svc->validateLines($lines, requireActiveAccounts: false);
        $this->assertFalse($res['valid']);
    }

    public function test_negative_credit_fails(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['account_id' => 1, 'debit' => 1, 'credit' => 0],
            ['account_id' => 2, 'debit' => 0, 'credit' => -1],
        ];

        $res = $svc->validateLines($lines, requireActiveAccounts: false);
        $this->assertFalse($res['valid']);
    }

    public function test_total_debit_calculates_correctly(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['debit' => '10.50'],
            ['debit' => '1.25'],
        ];

        $this->assertSame('11.75', $svc->totalDebit($lines));
    }

    public function test_total_credit_calculates_correctly(): void
    {
        $svc = new JournalValidationService();

        $lines = [
            ['credit' => '9.10'],
            ['credit' => '0.90'],
        ];

        $this->assertSame('10.00', $svc->totalCredit($lines));
    }
}

