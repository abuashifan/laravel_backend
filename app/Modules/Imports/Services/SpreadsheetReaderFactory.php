<?php

namespace App\Modules\Imports\Services;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Exceptions\ApiException;

class SpreadsheetReaderFactory implements \App\Shared\Export\TabularFileReader
{
    public function fileHeaders(string $extension, string $path): array
    {
        return $this->make($extension)->headers($path);
    }

    public function fileRows(string $extension, string $path): \Generator
    {
        return $this->make($extension)->rows($path);
    }

    public function make(string $extension): SpreadsheetReader
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => new CsvSpreadsheetReader,
            'xlsx' => new XlsxSpreadsheetReader,
            default => throw ApiException::make(
                ApiErrorCode::VALIDATION_ERROR,
                'Format berkas impor harus CSV atau XLSX.',
                422,
                ['file' => ['Format berkas impor harus CSV atau XLSX.']]
            ),
        };
    }
}
