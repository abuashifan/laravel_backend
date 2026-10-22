<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu dokumen sumber tetap hanya boleh punya SATU mutasi stok aktif per jenis
 * gerakan -- tapi mutasi yang sudah void tidak lagi dihitung.
 *
 * Mode open (edit transaksi yang sudah posted) membatalkan mutasi lama lalu
 * memposting ulang dokumen yang sama. Indeks unik lama ikut menghitung baris
 * void, jadi posting ulang gagal dengan "UNIQUE constraint failed". Mutasi
 * void tetap disimpan sebagai jejak audit; yang berubah hanya cakupan
 * indeksnya (indeks unik parsial, didukung SQLite & PostgreSQL).
 */
return new class extends Migration
{
    protected $connection = 'tenant';

    public function up(): void
    {
        if (! $this->supportsPartialIndex()) {
            return;
        }

        Schema::connection('tenant')->table('stock_movements', function (Blueprint $table) {
            $table->dropUnique('stock_movements_source_unique');
        });

        DB::connection('tenant')->statement(
            "CREATE UNIQUE INDEX stock_movements_source_unique ON stock_movements (source_type, source_id, movement_type) WHERE status <> 'void'"
        );
    }

    public function down(): void
    {
        if (! $this->supportsPartialIndex()) {
            return;
        }

        DB::connection('tenant')->statement('DROP INDEX IF EXISTS stock_movements_source_unique');

        Schema::connection('tenant')->table('stock_movements', function (Blueprint $table) {
            $table->unique(['source_type', 'source_id', 'movement_type'], 'stock_movements_source_unique');
        });
    }

    private function supportsPartialIndex(): bool
    {
        return in_array(DB::connection('tenant')->getDriverName(), ['sqlite', 'pgsql'], true);
    }
};
