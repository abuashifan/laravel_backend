<?php

namespace App\Data\Reports;

class LedgerFilter
{
    public function __construct(
        public ?string $startDate = null,
        public ?string $endDate = null,
        public ?int $accountId = null,
        public ?int $departmentId = null,
        public ?int $projectId = null,
        public bool $includeOpeningBalance = true,
        public bool $includeZeroBalance = false,
        public ?string $sortBy = 'journal_date',
        public string $sortDirection = 'asc',
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: $data['start_date'] ?? $data['startDate'] ?? null,
            endDate: $data['end_date'] ?? $data['endDate'] ?? null,
            accountId: isset($data['account_id']) ? (int) $data['account_id'] : (isset($data['accountId']) ? (int) $data['accountId'] : null),
            departmentId: isset($data['department_id']) ? (int) $data['department_id'] : (isset($data['departmentId']) ? (int) $data['departmentId'] : null),
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : (isset($data['projectId']) ? (int) $data['projectId'] : null),
            includeOpeningBalance: array_key_exists('include_opening_balance', $data)
                ? (bool) $data['include_opening_balance']
                : ((bool) ($data['includeOpeningBalance'] ?? true)),
            includeZeroBalance: array_key_exists('include_zero_balance', $data)
                ? (bool) $data['include_zero_balance']
                : ((bool) ($data['includeZeroBalance'] ?? false)),
            sortBy: $data['sort_by'] ?? $data['sortBy'] ?? 'journal_date',
            sortDirection: strtolower((string) ($data['sort_direction'] ?? $data['sortDirection'] ?? 'asc')) === 'desc' ? 'desc' : 'asc',
        );
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'account_id' => $this->accountId,
            'department_id' => $this->departmentId,
            'project_id' => $this->projectId,
            'include_opening_balance' => $this->includeOpeningBalance,
            'include_zero_balance' => $this->includeZeroBalance,
            'sort_by' => $this->sortBy,
            'sort_direction' => $this->sortDirection,
        ];
    }

    public function hasDateRange(): bool
    {
        return (bool) ($this->startDate || $this->endDate);
    }

    public function hasAccount(): bool
    {
        return (bool) $this->accountId;
    }

    public function hasDepartment(): bool
    {
        return (bool) $this->departmentId;
    }

    public function hasProject(): bool
    {
        return (bool) $this->projectId;
    }
}

