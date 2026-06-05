<?php

namespace App\Data\Reports;

class AccountLedgerLineData
{
    public function __construct(
        public int|string $journalEntryId,
        public int|string $journalEntryLineId,
        public string $journalNumber,
        public string $journalDate,
        public ?string $description,
        public int|string $accountId,
        public ?string $accountCode,
        public ?string $accountName,
        public float $debit,
        public float $credit,
        public float $runningBalance,
        public ?string $sourceType = null,
        public ?string $sourceNumber = null,
        public ?string $sourceModule = null,
        public ?int $sourceRevision = null,
        public ?int $departmentId = null,
        public ?string $departmentName = null,
        public ?int $projectId = null,
        public ?string $projectName = null,
    ) {
    }

    public static function fromRow(object $row, float $runningBalance): self
    {
        return new self(
            journalEntryId: $row->journal_entry_id,
            journalEntryLineId: $row->journal_entry_line_id,
            journalNumber: (string) $row->journal_number,
            journalDate: (string) $row->journal_date,
            description: $row->line_description ?? $row->journal_description ?? null,
            accountId: $row->account_id,
            accountCode: $row->account_code ?? null,
            accountName: $row->account_name ?? null,
            debit: (float) ($row->debit ?? 0),
            credit: (float) ($row->credit ?? 0),
            runningBalance: $runningBalance,
            sourceType: $row->source_type ?? null,
            sourceNumber: $row->source_number ?? null,
            sourceModule: $row->source_module ?? null,
            sourceRevision: isset($row->source_revision) ? (int) $row->source_revision : null,
            departmentId: isset($row->department_id) && $row->department_id ? (int) $row->department_id : null,
            departmentName: $row->department_name ?? null,
            projectId: isset($row->project_id) && $row->project_id ? (int) $row->project_id : null,
            projectName: $row->project_name ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'journal_entry_id' => $this->journalEntryId,
            'journal_entry_line_id' => $this->journalEntryLineId,
            'journal_number' => $this->journalNumber,
            'journal_date' => $this->journalDate,
            'description' => $this->description,
            'account_id' => $this->accountId,
            'account_code' => $this->accountCode,
            'account_name' => $this->accountName,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'running_balance' => $this->runningBalance,
            'source_type' => $this->sourceType,
            'source_number' => $this->sourceNumber,
            'source_module' => $this->sourceModule,
            'source_revision' => $this->sourceRevision,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
        ];
    }
}

