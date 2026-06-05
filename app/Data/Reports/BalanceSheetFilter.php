<?php

namespace App\Data\Reports;

class BalanceSheetFilter
{
    public function __construct(
        public string $asOfDate,
        public ?int $departmentId = null,
        public ?int $projectId = null,
        public bool $includeZeroBalance = false,
        public bool $includeInactiveAccounts = false,
        public string $groupBy = 'account_type',
    ) {
    }

    public static function fromArray(array $data): self
    {
        $groupBy = (string) ($data['group_by'] ?? 'account_type');
        if (! in_array($groupBy, ['account_type', 'none'], true)) {
            $groupBy = 'account_type';
        }

        return new self(
            asOfDate: (string) $data['as_of_date'],
            departmentId: isset($data['department_id']) ? (int) $data['department_id'] : null,
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
            includeZeroBalance: array_key_exists('include_zero_balance', $data) ? (bool) $data['include_zero_balance'] : false,
            includeInactiveAccounts: array_key_exists('include_inactive_accounts', $data) ? (bool) $data['include_inactive_accounts'] : false,
            groupBy: $groupBy,
        );
    }

    public function toArray(): array
    {
        return [
            'as_of_date' => $this->asOfDate,
            'department_id' => $this->departmentId,
            'project_id' => $this->projectId,
            'include_zero_balance' => $this->includeZeroBalance,
            'include_inactive_accounts' => $this->includeInactiveAccounts,
            'group_by' => $this->groupBy,
        ];
    }
}

