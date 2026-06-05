<?php

namespace App\Support\OpeningBalance;

use Carbon\Carbon;

class OpeningBalanceBatch
{
    /**
     * @param array<int, OpeningBalanceLine> $lines
     */
    public function __construct(
        public ?string $documentNumber,
        public ?string $openingDate,
        public ?int $fiscalYear,
        public string $type,
        private array $lines = [],
        public ?string $description = null,
        public array $metadata = [],
    ) {
    }

    public function addLine(OpeningBalanceLine $line): self
    {
        $this->lines[] = $line;
        return $this;
    }

    /**
     * @return array<int, OpeningBalanceLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    public function totalDebit(): float
    {
        return array_reduce($this->lines, fn (float $carry, OpeningBalanceLine $line) => $carry + $line->debitAmount(), 0.0);
    }

    public function totalCredit(): float
    {
        return array_reduce($this->lines, fn (float $carry, OpeningBalanceLine $line) => $carry + $line->creditAmount(), 0.0);
    }

    public function difference(): float
    {
        return $this->totalDebit() - $this->totalCredit();
    }

    public function isBalanced(float $tolerance = 0.0001): bool
    {
        return abs($this->difference()) <= $tolerance;
    }

    public function toArray(): array
    {
        return [
            'document_number' => $this->documentNumber,
            'opening_date' => $this->openingDate,
            'fiscal_year' => $this->fiscalYear,
            'type' => $this->type,
            'description' => $this->description,
            'lines' => array_map(fn (OpeningBalanceLine $l) => $l->toArray(), $this->lines),
            'metadata' => $this->metadata,
        ];
    }
}

