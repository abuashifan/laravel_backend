<?php

namespace App\Services\Reports;

use App\Data\Reports\LedgerFilter;
use App\Models\Tenant\Department;
use App\Models\Tenant\Project;
use Illuminate\Support\Facades\Schema;

class LedgerFilterValidator
{
    /**
     * @return array{valid:bool,errors:array}
     */
    public function validate(LedgerFilter $filter): array
    {
        $errors = [];

        if ($filter->departmentId !== null) {
            if (! $this->hasDepartmentColumn()) {
                $errors['department_id'][] = 'Department filter is not supported in this environment.';
            } elseif (! $this->departmentExists($filter->departmentId)) {
                $errors['department_id'][] = 'Department not found.';
            }
        }

        if ($filter->projectId !== null) {
            if (! $this->hasProjectColumn()) {
                $errors['project_id'][] = 'Project filter is not supported in this environment.';
            } elseif (! $this->projectExists($filter->projectId)) {
                $errors['project_id'][] = 'Project not found.';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    public function departmentExists(?int $departmentId): bool
    {
        if (! $departmentId) {
            return true;
        }

        return Department::query()->whereKey($departmentId)->exists();
    }

    public function projectExists(?int $projectId): bool
    {
        if (! $projectId) {
            return true;
        }

        return Project::query()->whereKey($projectId)->exists();
    }

    private function hasDepartmentColumn(): bool
    {
        return Schema::connection('tenant')->hasColumn('journal_entry_lines', 'department_id');
    }

    private function hasProjectColumn(): bool
    {
        return Schema::connection('tenant')->hasColumn('journal_entry_lines', 'project_id');
    }
}
