<?php

namespace App\Data\Reports;

class ReportMeta
{
    public function __construct(
        public string $reportName,
        public ?string $generatedAt,
        public array $filters = [],
        public array $dimensions = [],
        public ?array $fiscalYear = null,
        public array $notes = [],
    ) {
    }

    public static function make(
        string $reportName,
        array $filters = [],
        array $dimensions = [],
        ?array $fiscalYear = null,
        array $notes = [],
    ): self {
        return new self(
            reportName: $reportName,
            generatedAt: now()->toISOString(),
            filters: $filters,
            dimensions: $dimensions,
            fiscalYear: $fiscalYear,
            notes: $notes,
        );
    }

    public function toArray(): array
    {
        return [
            'report_name' => $this->reportName,
            'generated_at' => $this->generatedAt,
            'filters' => $this->filters,
            'dimensions' => $this->dimensions,
            'fiscal_year' => $this->fiscalYear,
            'notes' => $this->notes,
        ];
    }
}

