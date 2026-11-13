<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4c Finlite Kasir: indeks untuk toko ramai (2.000 transaksi per hari).
 *
 * Postgres tidak mengindeks foreign key otomatis (MySQL iya), dan migrasi
 * awal POS mengandalkan itu: `pos_sale_lines.sale_id` dan `product_id` tidak
 * punya indeks sama sekali. Akibatnya setiap join laporan, hitungan baris per
 * nota di daftar transaksi, dan penghapusan produk (aksi `ON DELETE SET NULL`
 * mencari baris yang menunjuk produk itu) memindai seluruh tabel baris
 * penjualan -- ratusan ribu baris setelah sebulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_sale_lines', function (Blueprint $table) {
            $table->index('sale_id');
            $table->index('product_id');
        });

        // Riwayat dan umur kasbon: transaksi satu pelanggan, terbaru dulu.
        Schema::table('pos_sales', function (Blueprint $table) {
            $table->index(['customer_id', 'sold_at'], 'pos_sales_customer_sold_index');
        });

        Schema::table('pos_credit_payments', function (Blueprint $table) {
            $table->index('customer_id');
            $table->index('shift_id');
        });

        // Rekap shift menjumlah retur dan pelunasan per shift.
        Schema::table('pos_sale_returns', function (Blueprint $table) {
            $table->index('sale_id');
            $table->index('shift_id');
        });

        Schema::table('pos_sale_return_lines', function (Blueprint $table) {
            $table->index('return_id');
            $table->index('sale_line_id');
        });

        Schema::table('pos_purchase_lines', function (Blueprint $table) {
            $table->index('purchase_id');
            $table->index('product_id');
        });

        Schema::table('pos_stock_count_lines', function (Blueprint $table) {
            $table->index('stock_count_id');
        });
    }

    public function down(): void
    {
        Schema::table('pos_stock_count_lines', fn (Blueprint $table) => $table->dropIndex(['stock_count_id']));
        Schema::table('pos_purchase_lines', function (Blueprint $table) {
            $table->dropIndex(['purchase_id']);
            $table->dropIndex(['product_id']);
        });
        Schema::table('pos_sale_return_lines', function (Blueprint $table) {
            $table->dropIndex(['return_id']);
            $table->dropIndex(['sale_line_id']);
        });
        Schema::table('pos_sale_returns', function (Blueprint $table) {
            $table->dropIndex(['sale_id']);
            $table->dropIndex(['shift_id']);
        });
        Schema::table('pos_credit_payments', function (Blueprint $table) {
            $table->dropIndex(['customer_id']);
            $table->dropIndex(['shift_id']);
        });
        Schema::table('pos_sales', fn (Blueprint $table) => $table->dropIndex('pos_sales_customer_sold_index'));
        Schema::table('pos_sale_lines', function (Blueprint $table) {
            $table->dropIndex(['sale_id']);
            $table->dropIndex(['product_id']);
        });
    }
};
