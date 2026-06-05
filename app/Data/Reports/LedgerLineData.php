<?php

namespace App\Data\Reports;

class LedgerLineData
{
    public function __construct(
        public int $journalEntryId,
        public string $journalNumber,
        public string $journalDate,
        public ?string $description,
        public int $accountId,
        public float $debit,
        public float $credit,
        public float $runningBalance,
        public ?int $departmentId = null,
        public ?string $departmentName = null,
        public ?int $projectId = null,
        public ?string $projectName = null,
        public ?string $sourceType = null,
        public ?string $sourceNumber = null,
        public ?string $sourceModule = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'journal_entry_id' => $this->journalEntryId,
            'journal_number' => $this->journalNumber,
            'journal_date' => $this->journalDate,
            'description' => $this->description,
            'account_id' => $this->accountId,
            'debit' => $this->debit,
            'credit' => $this->credit,
            'running_balance' => $this->runningBalance,
            'department_id' => $this->departmentId,
            'department_name' => $this->departmentName,
            'project_id' => $this->projectId,
            'project_name' => $this->projectName,
            'source_type' => $this->sourceType,
            'source_number' => $this->sourceNumber,
            'source_module' => $this->sourceModule,
        ];
    }
}

