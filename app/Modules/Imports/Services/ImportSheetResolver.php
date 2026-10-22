<?php

namespace App\Modules\Imports\Services;

use App\Shared\Api\ApiErrorCode;
use App\Shared\Exceptions\ApiException;

/**
 * Menentukan sheet .xlsx yang dibaca dari permintaan user. Dipakai bersama oleh
 * impor generik (`ImportBatchService`) dan impor berkas di wizard Setup (COA,
 * pemetaan akun), supaya semuanya menjawab "sheet mana?" dengan aturan yang
 * sama.
 */
final class ImportSheetResolver
{
    /**
     * Sheet yang dipakai: yang diminta, atau null (= sheet pertama) kalau tidak
     * ada yang diminta. Berkas tanpa sheet (CSV) selalu null.
     *
     * @param  list<string>  $sheets
     */
    public static function resolve(array $sheets, ?string $requested): ?string
    {
        $requested = trim((string) $requested);

        if ($sheets === [] || $requested === '') {
            return null;
        }

        if (! in_array($requested, $sheets, true)) {
            $message = sprintf(
                "Sheet '%s' tidak ada di berkas. Sheet yang tersedia: %s.",
                $requested,
                implode(', ', $sheets),
            );

            throw ApiException::make(ApiErrorCode::IMPORT_FILE_INVALID, $message, 422, ['sheet' => [$message]]);
        }

        // Sheet pertama disimpan sebagai null: sama artinya, dan data lama
        // (sebelum ada pilihan sheet) memang null.
        return $requested === $sheets[0] ? null : $requested;
    }
}
