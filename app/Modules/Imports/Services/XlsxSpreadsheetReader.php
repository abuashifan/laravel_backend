<?php

namespace App\Modules\Imports\Services;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class XlsxSpreadsheetReader implements SpreadsheetReader
{
    public function headers(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestColumn = $sheet->getHighestDataColumn();
        $row = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, false)[0] ?? [];
        $spreadsheet->disconnectWorksheets();

        return $this->normalizeRow($row);
    }

    public function rows(string $path): Generator
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestRow = $sheet->getHighestDataRow();
        $headers = $this->headers($path);

        try {
            for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                $row = $sheet->rangeToArray("A{$rowNumber}:{$highestColumn}{$rowNumber}", null, true, false)[0] ?? [];
                $values = $this->normalizeRow($row);

                if ($this->isBlank($values)) {
                    continue;
                }

                yield $rowNumber => $this->combine($headers, $values);
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
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
