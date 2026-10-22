<?php

namespace App\Modules\Imports\Services;

use Generator;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class XlsxSpreadsheetReader implements SpreadsheetReader
{
    /**
     * Sheet yang diminta, atau sheet PERTAMA kalau tidak ada yang diminta --
     * bukan `getActiveSheet()`.
     *
     * Templat unduhan berisi sheet kedua "Referensi" (daftar master data untuk
     * dropdown). Sheet aktif adalah sheet yang kebetulan terpilih saat berkas
     * terakhir disimpan — kalau user menutup Excel sambil membuka Referensi,
     * `getActiveSheet()` mengembalikan daftar master data dan SELURUH impor
     * gagal dengan pesan header tidak cocok, tanpa petunjuk penyebabnya.
     * Sheet lain hanya dibaca kalau user memilihnya sendiri.
     *
     * @throws UnknownImportSheetException
     */
    private function openSheet(string $path, ?string $sheetName): array
    {
        $spreadsheet = IOFactory::load($path);

        if ($sheetName === null || $sheetName === '') {
            return [$spreadsheet, $spreadsheet->getSheet(0)];
        }

        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            $available = $spreadsheet->getSheetNames();
            $spreadsheet->disconnectWorksheets();

            throw new UnknownImportSheetException($sheetName, $available);
        }

        return [$spreadsheet, $sheet];
    }

    public function sheetNames(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);

        // Hanya membaca daftar nama, bukan seluruh isi berkas.
        if (method_exists($reader, 'listWorksheetNames')) {
            return array_values($reader->listWorksheetNames($path));
        }

        $spreadsheet = IOFactory::load($path);
        $names = $spreadsheet->getSheetNames();
        $spreadsheet->disconnectWorksheets();

        return array_values($names);
    }

    public function headers(string $path, ?string $sheet = null): array
    {
        [$spreadsheet, $worksheet] = $this->openSheet($path, $sheet);
        $headers = $this->headerRow($worksheet);
        $spreadsheet->disconnectWorksheets();

        return $headers;
    }

    public function rows(string $path, ?string $sheet = null): Generator
    {
        [$spreadsheet, $worksheet] = $this->openSheet($path, $sheet);
        $highestColumn = $worksheet->getHighestDataColumn();
        $highestRow = $worksheet->getHighestDataRow();
        $headers = $this->headerRow($worksheet);

        try {
            for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
                $row = $worksheet->rangeToArray("A{$rowNumber}:{$highestColumn}{$rowNumber}", null, true, false)[0] ?? [];
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

    private function headerRow(Worksheet $sheet): array
    {
        $highestColumn = $sheet->getHighestDataColumn();
        $row = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, false)[0] ?? [];

        return $this->normalizeRow($row);
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
