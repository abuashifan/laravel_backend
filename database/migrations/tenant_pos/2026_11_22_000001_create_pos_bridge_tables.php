<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 5 Finlite Kasir: jembatan ke Finlite Accounting. Pengaturan per toko
 * dan daftar kiriman (satu baris per shift yang dikirim sebagai jurnal).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Satu baris. `account_map` menyimpan id akun di tenant AKUNTANSI (kunci
        // pemetaan -> id akun), bukan akun POS.
        Schema::create('pos_bridge_config', function (Blueprint $table) {
            $table->id();
            $table->boolean('enabled')->default(false);
            // Shift yang ditutup pada atau setelah tanggal ini ikut dikirim.
            $table->date('start_date')->nullable();
            // Ikut kirim HPP dan persediaan (memakai harga modal saat jual).
            $table->boolean('post_cogs')->default(false);
            $table->json('account_map')->nullable();
            $table->unsignedBigInteger('connected_by')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamps();
        });

        // Kunci idempoten (jenis, id sumber): satu shift tidak pernah jadi dua
        // jurnal, walau jembatan diputus dan disambung lagi.
        Schema::create('pos_bridge_dispatches', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 20);
            $table->unsignedBigInteger('source_id');
            $table->string('source_label');
            $table->date('entry_date');
            // pending | sent | failed | held (periode terkunci) | skipped (tanpa transaksi)
            $table->string('status', 12)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->string('journal_number', 60)->nullable();
            $table->text('error')->nullable();
            $table->json('summary')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'source_id']);
            $table->index(['status', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_bridge_dispatches');
        Schema::dropIfExists('pos_bridge_config');
    }
};
