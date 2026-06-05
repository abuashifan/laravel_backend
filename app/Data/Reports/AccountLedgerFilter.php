<?php

namespace App\Data\Reports;

class AccountLedgerFilter
{
    public function __construct(
        public ?string $startDate = null,
        public ?string $endDate = null,
        public int $accountId = 0,
        public ?int $departmentId = null,
        public ?int $projectId = null,
        public bool $includeOpeningBalance = true,
        public bool $includeZeroBalance = true,
        public bool $includeSourceInfo = true,
        public bool $includeDimensions = true,
        public string $sortDirection = 'asc',
    ) {
    }

    public static function fromArray(int $accountId, array $data): self
    {
        $dir = strtolower((string) ($data['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return new self(
            startDate: $data['start_date'] ?? null,
            endDate: $data['end_date'] ?? null,
            accountId: $accountId,
            departmentId: isset($data['department_id']) ? (int) $data['department_id'] : null,
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
            includeOpeningBalance: array_key_exists('include_opening_balance', $data) ? (bool) $data['include_opening_balance'] : true,
            includeZeroBalance: array_key_exists('include_zero_balance', $data) ? (bool) $data['include_zero_balance'] : true,
            includeSourceInfo: array_key_exists('include_source_info', $data) ? (bool) $data['include_source_info'] : true,
            includeDimensions: array_key_exists('include_dimensions', $data) ? (bool) $data['include_dimensions'] : true,
            sortDirection: $dir,
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
            'include_source_info' => $this->includeSourceInfo,
            'include_dimensions' => $this->includeDimensions,
            'sort_direction' => $this->sortDirection,
        ];
    }

    public function hasDateRange(): bool
    {
        return (bool) ($this->startDate || $this->endDate);
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

