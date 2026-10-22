<?php

namespace App\Modules\Imports\Services;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Exceptions\ApiException;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Daftar sheet sebuah berkas yang baru dipilih user, sebelum berkasnya
 * dibaca/diterapkan. Dipakai impor di wizard Setup yang membaca berkas dalam
 * satu permintaan (tanpa batch), supaya layarnya tahu perlu menampilkan pilihan
 * sheet atau tidak.
 */
class ImportSheetLister
{
    public function __construct(private readonly SpreadsheetReaderFactory $readerFactory) {}

    /**
     * @return array{sheets: list<string>}
     */
    public function list(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $reader = $this->readerFactory->make($extension);
        $path = $file->getRealPath();

        if ($path === false) {
            throw $this->invalid();
        }

        try {
            return ['sheets' => $reader->sheetNames($path)];
        } catch (Throwable) {
            throw $this->invalid();
        }
    }

    private function invalid(): ApiException
    {
        return ApiException::make(
            ApiErrorCode::IMPORT_FILE_INVALID,
            'Berkas kosong, rusak, atau tidak bisa dibaca.',
            422,
            ['file' => ['Berkas kosong, rusak, atau tidak bisa dibaca.']]
        );
    }
}
