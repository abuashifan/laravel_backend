<?php

namespace App\Http\Requests\Concerns;

use Closure;

trait HasReportDateFilters
{
    public function dateFilterRules(bool $required = false): array
    {
        $presence = $required ? 'required' : 'nullable';

        return [
            'start_date' => [$presence, 'date'],
            'end_date' => [$presence, 'date', $this->endDateAfterStartRule()],
        ];
    }

    protected function endDateAfterStartRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $start = $this->input('start_date');
            $startTimestamp = is_string($start) ? strtotime($start) : false;
            $endTimestamp = is_string($value) ? strtotime($value) : false;

            if ($startTimestamp !== false && $endTimestamp !== false && $endTimestamp < $startTimestamp) {
                $fail('The end date must be a date after or equal to start date.');
            }
        };
    }
}
