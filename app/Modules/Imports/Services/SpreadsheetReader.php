<?php

namespace App\Modules\Imports\Services;

use Generator;

interface SpreadsheetReader
{
    /**
     * @return list<string>
     */
    public function headers(string $path): array;

    /**
     * @return Generator<int,array<string,mixed>>
     */
    public function rows(string $path): Generator;
}
