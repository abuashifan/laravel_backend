<?php

namespace Tests\Unit;

use App\Services\OpeningBalance\OpeningBalanceService;
use App\Services\OpeningBalance\OpeningBalanceValidator;
use App\Support\OpeningBalance\OpeningBalanceBatch;
use App\Support\OpeningBalance\OpeningBalanceLine;
use Tests\TestCase;

class OpeningBalanceServiceTest extends TestCase
{
    public function test_opening_balance_line_to_array_works(): void
    {
        $line = OpeningBalanceLine::make(1, '101', 'Cash', 'asset', 100, 0, 'OB');
        $arr = $line->toArray();

        $this->assertSame(1, $arr['account_id']);
        $this->assertSame(100.0, $arr['debit']);
        $this->assertSame(0.0, $arr['credit']);
    }

    public function test_debit_and_credit_detection(): void
    {
        $debit = OpeningBalanceLine::make(1, null, null, 'asset', 10, 0);
        $credit = OpeningBalanceLine::make(2, null, null, 'liability', 0, 10);

        $this->assertTrue($debit->isDebit());
        $this->assertTrue($credit->isCredit());
    }

    public function test_line_with_both_debit_and_credit_is_invalid(): void
    {
        $validator = new OpeningBalanceValidator();
        $line = OpeningBalanceLine::make(1, null, null, 'asset', 10, 10);

        $this->assertNotEmpty($validator->validateLine($line));
    }

    public function test_negative_debit_and_credit_are_invalid(): void
    {
        $validator = new OpeningBalanceValidator();

        $this->assertNotEmpty($validator->validateLine(OpeningBalanceLine::make(1, null, null, 'asset', -1, 0)));
        $this->assertNotEmpty($validator->validateLine(OpeningBalanceLine::make(1, null, null, 'asset', 0, -1)));
    }

    public function test_batch_totals_and_balance(): void
    {
        $batch = new OpeningBalanceBatch(null, '2026-01-01', 2026, 'standard');
        $batch->addLine(OpeningBalanceLine::make(1, null, null, 'asset', 100, 0));
        $batch->addLine(OpeningBalanceLine::make(2, null, null, 'equity', 0, 100));

        $this->assertSame(100.0, $batch->totalDebit());
        $this->assertSame(100.0, $batch->totalCredit());
        $this->assertTrue($batch->isBalanced());
    }

    public function test_unbalanced_batch_is_not_balanced_and_validator_rejects(): void
    {
        $validator = new OpeningBalanceValidator();

        $batch = new OpeningBalanceBatch(null, '2026-01-01', 2026, 'standard');
        $batch->addLine(OpeningBalanceLine::make(1, null, null, 'asset', 100, 0));
        $batch->addLine(OpeningBalanceLine::make(2, null, null, 'equity', 0, 90));

        $this->assertFalse($batch->isBalanced());
        $res = $validator->validateBatch($batch);
        $this->assertFalse($res['valid']);
    }

    public function test_validator_accepts_balanced_real_account_types_batch(): void
    {
        $validator = new OpeningBalanceValidator();

        $batch = new OpeningBalanceBatch(null, '2026-01-01', 2026, 'standard');
        $batch->addLine(OpeningBalanceLine::make(1, null, null, 'asset', 100, 0));
        $batch->addLine(OpeningBalanceLine::make(2, null, null, 'equity', 0, 100));

        $res = $validator->validateBatch($batch);
        $this->assertTrue($res['valid']);
    }

    public function test_validator_rejects_nominal_account_type_by_default(): void
    {
        $validator = new OpeningBalanceValidator();

        $batch = new OpeningBalanceBatch(null, '2026-01-01', 2026, 'standard');
        $batch->addLine(OpeningBalanceLine::make(1, null, null, 'revenue', 100, 0));
        $batch->addLine(OpeningBalanceLine::make(2, null, null, 'equity', 0, 100));

        $res = $validator->validateBatch($batch);
        $this->assertFalse($res['valid']);
    }

    public function test_validator_warns_unknown_account_type(): void
    {
        $validator = new OpeningBalanceValidator();

        $batch = new OpeningBalanceBatch(null, '2026-01-01', 2026, 'standard');
        $batch->addLine(OpeningBalanceLine::make(1, null, null, 'unknown', 100, 0));
        $batch->addLine(OpeningBalanceLine::make(2, null, null, 'equity', 0, 100));

        $res = $validator->validateBatch($batch);
        $this->assertTrue($res['valid']);
        $this->assertNotEmpty($res['warnings']);
    }

    public function test_service_make_batch_and_prepare_journal_payload(): void
    {
        $service = new OpeningBalanceService(new OpeningBalanceValidator());

        $batch = $service->makeBatch([
            'opening_date' => '2026-01-01',
            'type' => 'standard',
            'lines' => [
                ['account_id' => 1, 'account_type' => 'asset', 'debit' => 100, 'credit' => 0],
                ['account_id' => 2, 'account_type' => 'equity', 'debit' => 0, 'credit' => 100],
            ],
        ]);

        $res = $service->validate($batch);
        $this->assertTrue($res['valid']);

        $payload = $service->prepareJournalPayload($batch);
        $this->assertSame('opening_balance', $payload['source_type']);
        $this->assertSame('opening_balance', $payload['document_type']);
        $this->assertSame('posted', $payload['status']);
        $this->assertCount(2, $payload['lines']);
    }

    public function test_source_data_returns_opening_balance_source_type_and_module(): void
    {
        $service = new OpeningBalanceService(new OpeningBalanceValidator());

        $data = $service->sourceData('OB-2026-000001', 1);
        $this->assertSame('opening_balance', $data['source_type']);
        $this->assertSame('opening_balance', $data['source_module']);
    }
}

