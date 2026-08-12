<?php

namespace App\Shared\Export;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
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
    /**
     * Ekspor hasil query builder ke file XLSX yang di-stream.
     *
     * @param  Builder  $query  Query TANPA paginasi (pemanggil bertanggung jawab
     *                           menerapkan filter yang sama dengan daftar).
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
