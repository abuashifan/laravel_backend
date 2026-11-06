<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3b Finlite Kasir: harga grosir per produk, pajak per toko, dan
 * pembulatan total transaksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            // [{"min_quantity": 10, "price": 3000}, ...] -- harga per jumlah beli.
            $table->json('price_tiers')->nullable();
        });

        Schema::table('pos_sales', function (Blueprint $table) {
            $table->decimal('tax_amount', 18, 2)->default(0);
            // true = harga sudah termasuk pajak (pajak tidak menambah total).
            $table->boolean('tax_inclusive')->default(false);
            $table->decimal('tax_rate', 5, 2)->default(0);
            // Selisih pembulatan total (bisa minus).
            $table->decimal('rounding_amount', 18, 2)->default(0);
        });

        Schema::table('pos_sale_returns', function (Blueprint $table) {
            // Bagian pajak dari uang retur, untuk laporan pendapatan bersih.
            $table->decimal('tax_amount', 18, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('pos_sale_returns', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        Schema::table('pos_sales', fn (Blueprint $table) => $table->dropColumn(['tax_amount', 'tax_inclusive', 'tax_rate', 'rounding_amount']));
        Schema::table('pos_products', fn (Blueprint $table) => $table->dropColumn('price_tiers'));
    }
};
