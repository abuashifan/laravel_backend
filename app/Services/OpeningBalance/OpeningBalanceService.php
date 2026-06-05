<?php

namespace App\Services\OpeningBalance;

use App\Services\Accounting\FiscalYearService;
use App\Services\DocumentNumbering\DocumentNumberService;
use App\Support\OpeningBalance\OpeningBalanceBatch;
use App\Support\OpeningBalance\OpeningBalanceLine;
use App\Support\OpeningBalance\OpeningBalanceType;
use Carbon\Carbon;

class OpeningBalanceService
{
    public function __construct(
        private readonly OpeningBalanceValidator $validator,
        private readonly ?DocumentNumberService $documentNumberService = null,
        private readonly ?FiscalYearService $fiscalYearService = null,
    ) {
    }

    public function makeBatch(array $data): OpeningBalanceBatch
    {
        $lines = [];
        foreach ((array) ($data['lines'] ?? []) as $line) {
            $lines[] = OpeningBalanceLine::make(
                $line['account_id'] ?? null,
                $line['account_code'] ?? null,
                $line['account_name'] ?? null,
                $line['account_type'] ?? null,
                $line['debit'] ?? 0,
                $line['credit'] ?? 0,
                $line['description'] ?? null,
                (array) ($line['metadata'] ?? [])
            );
        }

        return new OpeningBalanceBatch(
            $data['document_number'] ?? null,
            $data['opening_date'] ?? null,
            isset($data['fiscal_year']) ? (int) $data['fiscal_year'] : null,
            $data['type'] ?? OpeningBalanceType::STANDARD,
            $lines,
            $data['description'] ?? null,
            (array) ($data['metadata'] ?? [])
        );
    }

    public function validate(OpeningBalanceBatch $batch): array
    {
        return $this->validator->validateBatch($batch);
    }

    public function prepareJournalPayload(OpeningBalanceBatch $batch): array
    {
        $journalDate = $batch->openingDate ?? now()->toDateString();
        $documentNumber = $batch->documentNumber;

        return [
            'document_type' => $this->defaultDocumentType(),
            'source_type' => $this->defaultSourceType(),
            'source_module' => (string) config('opening_balance.source_module', 'opening_balance'),
            'document_number' => $documentNumber,
            'journal_date' => Carbon::parse($journalDate)->toDateString(),
            'description' => $batch->description ?? 'Opening balance',
            'status' => (string) config('opening_balance.default_status', 'posted'),
            'lines' => array_map(fn (OpeningBalanceLine $l) => [
                'account_id' => $l->accountId,
                'account_code' => $l->accountCode,
                'description' => $l->description ?? 'Opening balance',
                'debit' => $l->debitAmount(),
                'credit' => $l->creditAmount(),
                'metadata' => $l->metadata,
            ], $batch->lines()),
            'metadata' => $batch->metadata,
        ];
    }

    public function sourceData(?string $documentNumber = null, ?int $revision = 1): array
    {
        return [
            'source_type' => $this->defaultSourceType(),
            'source_module' => (string) config('opening_balance.source_module', 'opening_balance'),
            'source_number' => $documentNumber,
            'source_revision' => $revision ?? 1,
        ];
    }

    public function defaultDocumentType(): string
    {
        return (string) config('opening_balance.document_type', 'opening_balance');
    }

    public function defaultSourceType(): string
    {
        return (string) config('opening_balance.source_type', 'opening_balance');
    }
}

