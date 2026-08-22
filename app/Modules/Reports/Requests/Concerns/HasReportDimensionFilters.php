<?php

namespace App\Modules\Reports\Requests\Concerns;

trait HasReportDimensionFilters
{
    public function dimensionFilterRules(): array
    {
        return [
            'department_id' => ['nullable', 'integer'],
            'project_id' => ['nullable', 'integer'],
        ];
    }
}
