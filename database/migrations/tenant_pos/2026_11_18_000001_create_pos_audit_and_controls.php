<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 4b Finlite Kasir: log audit toko, batas diskon per kasir, dan PIN
 * kepala toko untuk aksi sensitif.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Jejak aksi sensitif. Hanya ditambah, tidak pernah diubah atau dihapus
        // dari aplikasi -- karena itu tanpa updated_at.
        Schema::create('pos_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 40);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();
            // Dokumen yang disentuh (sale, shift, product, ...) dan label yang
            // terbaca manusia (nomor transaksi, nama produk) -- label disalin
            // supaya jejaknya utuh walau dokumennya kelak dihapus.
            $table->string('subject_type', 30)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label')->nullable();
            $table->string('summary');
            $table->json('changes')->nullable();
            $table->string('reason')->nullable();
            // Aksi ini disetujui dengan PIN kepala toko (kasir tanpa izin sendiri).
            $table->boolean('approved_with_pin')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['action', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // Batas diskon per pengguna toko. Satu baris per pengguna; kosong = ikut
        // batas bawaan toko (pengaturan `max_discount_percent`).
        Schema::create('pos_member_limits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->decimal('max_discount_percent', 5, 2)->nullable();
            $table->timestamps();
        });

        // Satu PIN kepala toko per toko. Disimpan sebagai hash di tabel sendiri,
        // bukan di pos_settings: pengaturan dibaca semua pengguna lewat API.
        Schema::create('pos_supervisor_pins', function (Blueprint $table) {
            $table->id();
            $table->string('pin_hash');
            $table->unsignedBigInteger('set_by')->nullable();
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_supervisor_pins');
        Schema::dropIfExists('pos_member_limits');
        Schema::dropIfExists('pos_audit_logs');
    }
};
