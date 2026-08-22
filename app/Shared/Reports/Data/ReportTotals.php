<?php

namespace App\Shared\Reports\Data;

class ReportTotals
{
    public function __construct(public array $values) {}

    public static function make(array $values): self
    {
        return new self($values);
    }

    public function toArray(): array
    {
        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }
}
