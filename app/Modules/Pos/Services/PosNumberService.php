<?php

namespace App\Modules\Pos\Services;

use Illuminate\Database\Eloquent\Model;

/**
 * Nomor dokumen kasir: `{PREFIX}-{YYMMDD}-{0001}`, berurut per hari.
 * Dipanggil di dalam transaksi; kolom nomor UNIQUE menjadi pengaman
 * terakhir bila dua kasir menyimpan di detik yang sama.
 */
class PosNumberService
{
    public function __construct(private readonly PosClock $clock) {}

    /** @param class-string<Model> $modelClass */
    public function next(string $modelClass, string $prefix, string $column = 'number'): string
    {
        $head = $prefix.'-'.$this->clock->format(now(), 'ymd').'-';

        $last = $modelClass::query()
            ->where($column, 'like', $head.'%')
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        $sequence = $last ? ((int) substr((string) $last, strlen($head))) + 1 : 1;

        return $head.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
