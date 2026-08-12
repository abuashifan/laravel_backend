<?php

namespace App\Modules\Imports\Services;

use RuntimeException;

class DuplicateImportFileException extends RuntimeException
{
    public function __construct(public readonly array $duplicate)
    {
        parent::__construct('Berkas ini sudah pernah diunggah.');
    }
}
