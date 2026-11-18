<?php

namespace App\Shared\Export;

interface TabularFileReader
{
    public function fileHeaders(string $extension, string $path): array;
    public function fileRows(string $extension, string $path): \Generator;
}
