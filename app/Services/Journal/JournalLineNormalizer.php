<?php

namespace App\Services\Journal;

class JournalLineNormalizer
{
    /**
     * @param array<int,array<string,mixed>> $lines
     * @return array<int,array<string,mixed>>
     */
    public function normalize(array $lines): array
    {
        $normalized = [];

        foreach (array_values($lines) as $index => $line) {
            if (! is_array($line)) {
                continue;
            }

            $normalizedLine = $this->normalizeLine($line, $index);
            $normalized[] = $normalizedLine;
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    public function normalizeLine(array $line, int $index): array
    {
        $debit = $line['debit'] ?? 0;
        $credit = $line['credit'] ?? 0;

        return [
            'account_id' => isset($line['account_id']) ? (int) $line['account_id'] : null,
            'department_id' => isset($line['department_id']) && $line['department_id'] !== '' ? (int) $line['department_id'] : null,
            'project_id' => isset($line['project_id']) && $line['project_id'] !== '' ? (int) $line['project_id'] : null,
            'description' => isset($line['description']) && $line['description'] !== '' ? (string) $line['description'] : null,
            'debit' => $this->normalizeNumber($debit),
            'credit' => $this->normalizeNumber($credit),
            'line_order' => isset($line['line_order']) ? (int) $line['line_order'] : ($index + 1),
            'metadata' => isset($line['metadata']) ? (array) $line['metadata'] : null,
        ];
    }

    private function normalizeNumber(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0';
        }

        if (is_int($value) || is_float($value)) {
            return number_format((float) $value, 2, '.', '');
        }

        if (is_string($value)) {
            $clean = str_replace(',', '', trim($value));
            if ($clean === '') {
                return '0';
            }

            return number_format((float) $clean, 2, '.', '');
        }

        return '0';
    }
}
