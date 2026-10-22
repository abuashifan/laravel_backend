<?php

namespace App\Modules\Imports\Services;

use Generator;

interface SpreadsheetReader
{
    /**
     * Nama sheet di berkas, sesuai urutannya. Format tanpa sheet (CSV)
     * mengembalikan array kosong.
     *
     * @return list<string>
     */
    public function sheetNames(string $path): array;

    /**
     * `$sheet` null berarti sheet pertama. Format tanpa sheet mengabaikannya.
     *
     * @return list<string>
     */
    public function headers(string $path, ?string $sheet = null): array;

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function rows(string $path, ?string $sheet = null): Generator;
}
