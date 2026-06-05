<?php

namespace App\Data\Reports;

class ReportDimensionFilter
{
    public function __construct(
        public ?int $departmentId = null,
        public ?int $projectId = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            departmentId: isset($data['department_id']) ? (int) $data['department_id'] : null,
            projectId: isset($data['project_id']) ? (int) $data['project_id'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'department_id' => $this->departmentId,
            'project_id' => $this->projectId,
        ];
    }

    public function hasDepartment(): bool
    {
        return (bool) $this->departmentId;
    }

    public function hasProject(): bool
    {
        return (bool) $this->projectId;
    }

    public function hasAnyDimension(): bool
    {
        return $this->hasDepartment() || $this->hasProject();
    }
}

