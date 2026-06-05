<?php

namespace App\Data\Reports;

class ReportResponse
{
    public function __construct(
        public ReportMeta $meta,
        public array $data,
        public ?ReportTotals $totals = null,
    ) {
    }

    public static function make(ReportMeta $meta, array $data, ?ReportTotals $totals = null): self
    {
        return new self($meta, $data, $totals);
    }

    public function toArray(): array
    {
        return [
            'meta' => $this->meta->toArray(),
            'data' => $this->data,
            'totals' => $this->totals?->toArray(),
        ];
    }
}

