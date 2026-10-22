<?php

namespace App\Modules\Imports\Services;

use RuntimeException;

/**
 * Sheet yang dipilih user tidak ada di berkas. Dibedakan dari galat baca
 * biasa supaya pesannya bisa menyebut sheet yang tersedia, bukan sekadar
 * "berkas rusak".
 */
class UnknownImportSheetException extends RuntimeException
{
    /**
     * @param  list<string>  $available
     */
    public function __construct(
        public readonly string $sheet,
        public readonly array $available,
    ) {
        parent::__construct("Sheet '{$sheet}' tidak ada di berkas.");
    }
}
