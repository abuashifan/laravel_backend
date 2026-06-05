<?php

namespace App\Data\Reports;

class TrialBalanceFilter
{
    public function __construct(
        public ?string $startDate = null,
        public ?string $endDate = null,
        public ?int $departmentId = null,
        public ?int $projectId = null,
        public bool $includeZeroBalance = false,
        public bool $includeInactiveAccounts = false,
        public ?string $accountType = null,
        public string $sortBy = 'account_code',
        public string $sortDirection = 'asc',
    ) {
    }

    public static function fromArray(array $data): self
    {
        $dir = strtolower((string) ($data['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return new self(
            startDate: $data['start_date'] ?? null,
            endDate: $data['end_date'] ?? null,
            departmentId: isset($data['department_id']) ? (int) $data['department_id'] : null,
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
            includeZeroBalance: array_key_exists('include_zero_balance', $data) ? (bool) $data['include_zero_balance'] : false,
            includeInactiveAccounts: array_key_exists('include_inactive_accounts', $data) ? (bool) $data['include_inactive_accounts'] : false,
            accountType: $data['account_type'] ?? null,
            sortBy: $data['sort_by'] ?? 'account_code',
            sortDirection: $dir,
        );
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'department_id' => $this->departmentId,
            'project_id' => $this->projectId,
            'include_zero_balance' => $this->includeZeroBalance,
            'include_inactive_accounts' => $this->includeInactiveAccounts,
            'account_type' => $this->accountType,
            'sort_by' => $this->sortBy,
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

    public function hasAccountType(): bool
    {
        return (bool) $this->accountType;
    }
}

