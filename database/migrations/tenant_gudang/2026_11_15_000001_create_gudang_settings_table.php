<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Finlite Gudang, Fase 0: tenant Gudang baru berisi tabel pengaturan saja.
 * Tabel barang, lokasi, dan buku stok datang di Fase 1 (dokumen 11).
 */
return new class extends Migration
{
    protected $connection = 'gudang';

    public function up(): void
    {
        Schema::connection('gudang')->create('gudang_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 80)->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('gudang')->dropIfExists('gudang_settings');
    }
};
