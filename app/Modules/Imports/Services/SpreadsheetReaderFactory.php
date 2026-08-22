<?php

namespace App\Modules\Imports\Services;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Exceptions\ApiException;

class SpreadsheetReaderFactory
{
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
