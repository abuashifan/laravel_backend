<?php

namespace App\Shared\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Ekspor data ke Excel — dipakai halaman daftar dan hasil impor (Fase 6).
 *
 * Memakai PhpSpreadsheet langsung (sudah terpasang sejak Fase 0), bukan
 * maatwebsite/excel — konsisten dengan pembaca di sisi impor.
 */
class ExcelExportService
{
    /** Judul sheet daftar master data di templat impor. */
    private const REFERENCE_SHEET = 'Referensi';

    /** Berapa baris sheet data yang dipasangi dropdown. */
    private const VALIDATED_ROWS = 500;

    /**
     * Ekspor hasil query builder ke file XLSX yang di-stream.
     *
     * @param  Builder  $query  Query TANPA paginasi (pemanggil bertanggung jawab
     *                          menerapkan filter yang sama dengan daftar).
     * @param  array<int, string>  $headers  Nama kolom di baris pertama.
     * @param  callable  $mapper  Fungsi yang mengubah satu baris model jadi
     *                            array nilai (urutan harus sama dengan $headers).
     * @param  string  $filename  Nama berkas unduhan (tanpa .xlsx).
     */
    public function download(
        Builder $query,
        array $headers,
        callable $mapper,
        string $filename = 'export'
    ): StreamedResponse {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Header
        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }

        // Data — iterasi per 500 baris untuk menghemat memori.
        $row = 2;
        $query->chunk(500, function (Collection $chunk) use ($sheet, $mapper, &$row): void {
            foreach ($chunk as $model) {
                $values = $mapper($model);
                foreach ($values as $col => $value) {
                    $sheet->setCellValue([$col + 1, $row], $value);
                }
                $row++;
            }
        });

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Templat impor: sheet data + sheet referensi master data + dropdown.
     *
     * Berbeda dari `downloadFromArray()` yang hanya menumpahkan satu tabel.
     * Yang dipecahkan di sini adalah keluhan paling sering soal impor: user
     * tidak tahu isi kolom yang WAJIB cocok dengan master data sistem
     * (kategori aset, kode akun, departemen), dan mengetiknya dari ingatan.
     *
     * Tiga lapis, dari yang paling lunak ke paling keras:
     *
     * 1. Sheet "Referensi" memperlihatkan daftar sahnya, lengkap dengan nama —
     *    user bisa membacanya tanpa membuka aplikasi.
     * 2. Dropdown di kolom data menunjuk ke daftar itu, jadi nilainya dipilih.
     * 3. Validasi backend tetap yang berkuasa: dropdown hilang begitu berkas
     *    disimpan sebagai CSV atau isinya ditempel dari sistem lain.
     *
     * Sheet data WAJIB indeks 0 — `XlsxSpreadsheetReader` membaca sheet
     * pertama, bukan sheet yang kebetulan aktif.
     *
     * @param  array{filename:string, headers:list<string>, rows:list<list<string>>, fields?:list<string>, reference?:list<array{field:?string, title:string, headers:list<string>, rows:list<list<string>>}>, enums?:array<string, list<string>>}  $template
     */
    public function downloadTemplate(array $template): StreamedResponse
    {
        $headers = array_values((array) $template['headers']);
        $fields = array_values((array) ($template['fields'] ?? []));
        $reference = (array) ($template['reference'] ?? []);
        $enums = (array) ($template['enums'] ?? []);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data');

        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
            $sheet->getColumnDimensionByColumn($col + 1)->setAutoSize(true);

            // Kolom tanggal dipaksa bertipe Teks. Kalau tidak, Excel mengubah
            // '15/03/2023' yang diketik user jadi tanggal sungguhan, lalu
            // menyimpannya sebagai angka seri — dan `XlsxSpreadsheetReader`
            // membaca angka seri itu apa adanya, sehingga barisnya gagal
            // dengan pesan "format harus DD/MM/YYYY" padahal di layar Excel
            // tampak sudah benar.
            if (isset($fields[$col]) && str_ends_with((string) $fields[$col], '_date')) {
                $letter = Coordinate::stringFromColumnIndex($col + 1);
                $sheet->getStyle(sprintf('%s2:%s%d', $letter, $letter, self::VALIDATED_ROWS + 1))
                    ->getNumberFormat()
                    ->setFormatCode(NumberFormat::FORMAT_TEXT);
            }
        }
        $sheet->getStyle([1, 1, max(1, count($headers)), 1])->getFont()->setBold(true);
        $sheet->freezePane('A2');

        foreach ((array) $template['rows'] as $rowIndex => $row) {
            foreach ((array) $row as $col => $value) {
                $sheet->setCellValueExplicit([$col + 1, $rowIndex + 2], (string) $value, DataType::TYPE_STRING);
            }
        }

        $sources = $this->writeReferenceSheet($spreadsheet, $reference);

        foreach ($enums as $field => $values) {
            // Daftar harfiah dibatasi 255 karakter oleh format xlsx; daftar
            // sepanjang itu memang tempatnya di sheet Referensi.
            $sources[$field] = '"'.implode(',', (array) $values).'"';
        }

        foreach ($sources as $field => $formula) {
            $columnIndex = array_search($field, $fields, true);
            if ($columnIndex === false) {
                continue;
            }

            $this->applyListValidation($sheet, (int) $columnIndex + 1, $formula);
        }

        $spreadsheet->setActiveSheetIndex(0);

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $template['filename'].'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Tulis sheet "Referensi": tiap blok satu kelompok kolom, dipisah satu
     * kolom kosong. Mengembalikan peta field → rumus rentang untuk dropdown.
     *
     * @param  list<array{field:?string, title:string, headers:list<string>, rows:list<list<string>>}>  $reference
     * @return array<string, string>
     */
    private function writeReferenceSheet(Spreadsheet $spreadsheet, array $reference): array
    {
        if ($reference === []) {
            return [];
        }

        $sheet = $spreadsheet->createSheet();
        $sheet->setTitle(self::REFERENCE_SHEET);
        $sources = [];
        $column = 1;

        foreach ($reference as $block) {
            $blockHeaders = array_values((array) $block['headers']);
            $blockRows = (array) $block['rows'];
            $width = max(1, count($blockHeaders));

            $sheet->setCellValue([$column, 1], (string) $block['title']);
            $sheet->getStyle([$column, 1, $column, 1])->getFont()->setBold(true);

            foreach ($blockHeaders as $offset => $header) {
                $sheet->setCellValue([$column + $offset, 2], $header);
                $sheet->getColumnDimensionByColumn($column + $offset)->setAutoSize(true);
            }
            $sheet->getStyle([$column, 2, $column + $width - 1, 2])->getFont()->setBold(true);

            foreach ($blockRows as $rowOffset => $row) {
                foreach ((array) $row as $offset => $value) {
                    $sheet->setCellValueExplicit([$column + $offset, $rowOffset + 3], (string) $value, DataType::TYPE_STRING);
                }
            }

            if (($block['field'] ?? null) !== null) {
                // Kolom pertama blok selalu kolom kodenya — itu yang dikirim
                // ke backend; kolom sisanya hanya supaya daftarnya terbaca.
                $letter = Coordinate::stringFromColumnIndex($column);
                $sources[(string) $block['field']] = sprintf(
                    "'%s'!$%s$3:$%s$%d",
                    self::REFERENCE_SHEET,
                    $letter,
                    $letter,
                    count($blockRows) + 2,
                );
            }

            $column += $width + 1;
        }

        return $sources;
    }

    /**
     * Kunci satu kolom sheet data ke daftar nilai.
     *
     * `allowBlank` menyala karena hampir semua kolom bermaster data bersifat
     * opsional (departemen, proyek); yang wajib dijaga `required_fields` di
     * backend, bukan Excel. Gaya galat STOP dipilih supaya salah ketik benar-
     * benar tertahan — user yang baru menambah master data baru tinggal
     * mengunduh templatnya lagi.
     */
    private function applyListValidation(Worksheet $sheet, int $columnIndex, string $formula): void
    {
        $letter = Coordinate::stringFromColumnIndex($columnIndex);
        $range = sprintf('%s2:%s%d', $letter, $letter, self::VALIDATED_ROWS + 1);

        $validation = $sheet->getCell($letter.'2')->getDataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setErrorTitle('Nilai tidak dikenal');
        $validation->setError('Pilih salah satu nilai dari daftar. Daftar lengkapnya ada di sheet '.self::REFERENCE_SHEET.'.');
        $validation->setPromptTitle('Pilih dari daftar');
        $validation->setPrompt('Isi kolom ini dengan memilih dari dropdown, bukan mengetik.');
        $validation->setFormula1($formula);

        $sheet->setDataValidation($range, $validation);
    }

    /**
     * Ekspor array data mentah ke XLSX — dipakai untuk hasil impor.
     *
     * @param  array<int, array<int, string>>  $rows
     * @param  array<int, string>  $headers
     */
    public function downloadFromArray(array $rows, array $headers, string $filename = 'export'): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $col => $value) {
                $sheet->setCellValue([$col + 1, $rowIndex + 2], $value);
            }
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }
}
