<?php

namespace App\Modules\Imports\Services;

use Generator;
use RuntimeException;

class CsvSpreadsheetReader implements SpreadsheetReader
{
    public function headers(string $path): array
    {
        $handle = $this->open($path);
        $headers = $this->normalizeRow(fgetcsv($handle) ?: []);
        fclose($handle);

        return $headers;
    }

    public function rows(string $path): Generator
    {
        $handle = $this->open($path);
        $headers = $this->normalizeRow(fgetcsv($handle) ?: []);
        $rowNumber = 1;

        try {
            while (($row = fgetcsv($handle)) !== false) {
                $rowNumber++;
                $values = $this->normalizeRow($row);

                if ($this->isBlank($values)) {
                    continue;
                }

                yield $rowNumber => $this->combine($headers, $values);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return resource
     */
    private function open(string $path)
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException('File CSV tidak bisa dibuka.');
        }

        return $handle;
    }

    private function normalizeRow(array $row): array
    {
        return array_values(array_map(
            fn (mixed $value): string => trim((string) ($value ?? '')),
            $row
        ));
    }

    private function combine(array $headers, array $values): array
    {
        $combined = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $combined[$header] = $values[$index] ?? null;
        }

        return $combined;
    }

    private function isBlank(array $values): bool
    {
        return collect($values)->every(fn (mixed $value): bool => trim((string) ($value ?? '')) === '');
    }
}
