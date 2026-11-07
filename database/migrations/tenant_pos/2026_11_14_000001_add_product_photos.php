<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 3c Finlite Kasir: foto produk.
 *
 * Foto disimpan di database toko, bukan di disk server: disk kontainer Render
 * hilang tiap deploy, dan ukuran database tenant memang yang dihitung kuota.
 * Aplikasi kasir sudah memperkecil foto sebelum diunggah (sisi panjang 480px).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_products', function (Blueprint $table) {
            // Gambar mini (data URL, ~3 KB) ikut di daftar produk untuk layar kasir.
            $table->text('photo_thumb')->nullable();
        });

        Schema::create('pos_product_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained('pos_products')->cascadeOnDelete();
            // Data URL foto ukuran penuh, hanya dimuat di halaman detail produk.
            $table->longText('data');
            // Ukuran byte gambar (foto + gambar mini), untuk kuota foto toko.
            $table->unsignedInteger('bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_product_photos');
        Schema::table('pos_products', fn (Blueprint $table) => $table->dropColumn('photo_thumb'));
    }
};
