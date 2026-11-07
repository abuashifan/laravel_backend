<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4a Finlite Kasir: stok minimum per produk untuk laporan stok menipis,
 * plus indeks yang dipakai laporan per kasir dan per shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            // Kosong = dianggap 0 (menipis hanya kalau habis).
            $table->decimal('min_stock', 14, 3)->nullable();
        });

        Schema::table('pos_sales', function (Blueprint $table) {
            $table->index(['status', 'sold_at']);
            $table->index('shift_id', 'pos_sales_shift_report_index');
        });

        Schema::table('pos_shifts', function (Blueprint $table) {
            $table->index('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('pos_shifts', fn (Blueprint $table) => $table->dropIndex(['opened_at']));
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->dropIndex('pos_sales_shift_report_index');
            $table->dropIndex(['status', 'sold_at']);
        });
        Schema::table('pos_products', fn (Blueprint $table) => $table->dropColumn('min_stock'));
    }
};
