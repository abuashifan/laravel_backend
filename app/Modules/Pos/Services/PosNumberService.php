<?php

namespace App\Modules\Pos\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Nomor dokumen kasir: `{PREFIX}-{YYMMDD}-{0001}`, berurut per hari.
 *
 * Dipanggil di dalam transaksi simpan. Nomor diambil dari penghitung atomik
 * (`pos_number_sequences`): `UPDATE ... SET last_value = last_value + 1` mengunci
 * barisnya sampai transaksi selesai, jadi dua kasir yang menyimpan bersamaan
 * dilayani bergantian dan tidak pernah mendapat nomor yang sama. Kalau transaksi
 * dibatalkan, penghitungnya ikut dibatalkan, jadi nomor tidak bolong.
 *
 * Cara lama (`SELECT ... FOR UPDATE ... LIMIT 1` pada nomor terakhir) hanya
 * mengunci satu baris dan tidak mengunci apa pun di transaksi pertama hari itu:
 * di Postgres dua kasir bisa mendapat nomor sama, lalu salah satunya gagal 500
 * di kolom nomor yang UNIQUE.
 */
class PosNumberService
{
    public function __construct(private readonly PosClock $clock) {}

    /** @param class-string<Model> $modelClass */
    public function next(string $modelClass, string $prefix, string $column = 'number'): string
    {
        $head = $prefix.'-'.$this->clock->format(now(), 'ymd').'-';
        $table = DB::connection('pos')->table('pos_number_sequences');

        if (! (clone $table)->where('scope', $head)->exists()) {
            // Hari ini belum punya penghitung (hari baru, atau dokumen dibuat
            // sebelum tabel ini ada): mulai dari nomor tertinggi yang tersimpan.
            // Dua kasir yang sampai di sini bersamaan sama-sama mencoba menyisipkan;
            // satu menang, yang lain diabaikan, lalu keduanya naik bergantian di bawah.
            $last = $modelClass::query()->where($column, 'like', $head.'%')->orderByDesc($column)->value($column);
            $start = $last ? (int) substr((string) $last, strlen($head)) : 0;
            (clone $table)->insertOrIgnore(['scope' => $head, 'last_value' => $start, 'created_at' => now(), 'updated_at' => now()]);
        }

        (clone $table)->where('scope', $head)->update(['last_value' => DB::raw('last_value + 1'), 'updated_at' => now()]);
        $sequence = (int) (clone $table)->where('scope', $head)->value('last_value');

        return $head.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }
}
