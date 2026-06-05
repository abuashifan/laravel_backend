<?php

namespace App\Data\Reports;

class CashFlowFilter
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public ?int $departmentId = null,
        public ?int $projectId = null,
        public bool $includeAccountBreakdown = true,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: (string) $data['start_date'],
            endDate: (string) $data['end_date'],
            departmentId: isset($data['department_id']) ? (int) $data['department_id'] : null,
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
            includeAccountBreakdown: array_key_exists('include_account_breakdown', $data) ? (bool) $data['include_account_breakdown'] : true,
        );
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'department_id' => $this->departmentId,
            'project_id' => $this->projectId,
            'include_account_breakdown' => $this->includeAccountBreakdown,
        ];
    }
}

