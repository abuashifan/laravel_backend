<?php

namespace App\Data\Reports;

use Carbon\Carbon;

class ReportDateRange
{
    public function __construct(
        public ?string $startDate = null,
        public ?string $endDate = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            startDate: $data['start_date'] ?? null,
            endDate: $data['end_date'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }

    public function hasStartDate(): bool
    {
        return (bool) $this->startDate;
    }

    public function hasEndDate(): bool
    {
        return (bool) $this->endDate;
    }

    public function hasRange(): bool
    {
        return $this->hasStartDate() || $this->hasEndDate();
    }

    /**
     * @return array{start_date:?string,end_date:?string}
     */
    public function forOpeningQuery(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => null,
        ];
    }

    /**
     * @return array{start_date:?string,end_date:?string}
     */
    public function forPeriodQuery(): array
    {
        return [
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
        ];
    }

    public function normalizeToDateString(): self
    {
        $start = $this->startDate ? Carbon::parse($this->startDate)->toDateString() : null;
        $end = $this->endDate ? Carbon::parse($this->endDate)->toDateString() : null;

        return new self($start, $end);
    }
}

