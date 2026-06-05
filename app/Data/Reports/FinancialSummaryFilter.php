<?php

namespace App\Data\Reports;

class FinancialSummaryFilter
{
    public function __construct(
        public string $startDate,
        public string $endDate,
        public string $asOfDate,
        public ?int $departmentId = null,
        public ?int $projectId = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $endDate = (string) $data['end_date'];
        $asOfDate = (string) ($data['as_of_date'] ?? $endDate);

        return new self(
            startDate: (string) $data['start_date'],
            endDate: $endDate,
            asOfDate: $asOfDate,
            departmentId: isset($data['department_id']) ? (int) $data['department_id'] : null,
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'as_of_date' => $this->asOfDate,
            'department_id' => $this->departmentId,
            'project_id' => $this->projectId,
        ];
    }
}

