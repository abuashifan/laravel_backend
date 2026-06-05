<?php

namespace App\Support\DocumentNumbering;

class DocumentNumberFormat
{
    public static function format(string $format, array $tokens): string
    {
        $replacements = [];

        foreach ($tokens as $key => $value) {
            $replacements['{'.strtoupper((string) $key).'}'] = (string) $value;
        }

        return strtr($format, $replacements);
    }

    public static function padNumber(int $number, int $padding): string
    {
        return str_pad((string) $number, max(0, $padding), '0', STR_PAD_LEFT);
    }
}

