<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4c Finlite Kasir: penghitung nomor dokumen yang aman untuk beberapa
 * kasir yang menyimpan bersamaan. Satu baris per awalan per hari, misalnya
 * `PJ-261113-`; lihat `PosNumberService`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_number_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 60)->unique();
            $table->unsignedInteger('last_value')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_number_sequences');
    }
};
