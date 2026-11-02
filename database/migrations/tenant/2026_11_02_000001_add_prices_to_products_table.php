<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Harga acuan yang diisi otomatis ke baris transaksi; tetap bisa diubah per dokumen.
            $table->decimal('purchase_price', 18, 2)->default(0)->after('min_stock');
            $table->decimal('selling_price', 18, 2)->default(0)->after('purchase_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['purchase_price', 'selling_price']);
        });
    }
};
