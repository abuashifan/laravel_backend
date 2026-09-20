<?php

namespace App\Modules\Imports\Services;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;

class XlsxSpreadsheetReader implements SpreadsheetReader
{
    /**
     * Selalu sheet PERTAMA, bukan `getActiveSheet()`.
     *
     * Templat unduhan berisi sheet kedua "Referensi" (daftar master data untuk
     * dropdown). Sheet aktif adalah sheet yang kebetulan terpilih saat berkas
     * terakhir disimpan — kalau user menutup Excel sambil membuka Referensi,
     * `getActiveSheet()` mengembalikan daftar master data dan SELURUH impor
     * gagal dengan pesan header tidak cocok, tanpa petunjuk penyebabnya.
     */
    private function firstSheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);

        return [$spreadsheet, $spreadsheet->getSheet(0)];
    }

    public function headers(string $path): array
    {
        [$spreadsheet, $sheet] = $this->firstSheet($path);
        $highestColumn = $sheet->getHighestDataColumn();
        $row = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, false)[0] ?? [];
        $spreadsheet->disconnectWorksheets();

        return $this->normalizeRow($row);
    }

    public function rows(string $path): Generator
    {
        [$spreadsheet, $sheet] = $this->firstSheet($path);
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
