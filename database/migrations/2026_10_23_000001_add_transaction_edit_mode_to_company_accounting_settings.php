<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode ubah transaksi per perusahaan.
 *
 * - `lock` (bawaan): hanya transaksi DRAFT yang boleh diedit/dihapus. Yang sudah
 *   approved/posted harus di-void dulu. Sales Order & Delivery Order tetap
 *   mengikuti aturannya sendiri (belum membukukan jurnal).
 * - `open`: transaksi status apa pun (kecuali void) boleh diedit/dihapus,
 *   SELAMA tidak ada transaksi lain yang tertaut padanya.
 *
 * Bawaan `lock` juga untuk perusahaan yang sudah ada -- keputusan pemilik
 * aplikasi (lebih aman). Menggantikan peran `allow_edit_posted_transactions`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_accounting_settings', function (Blueprint $table) {
            $table->string('transaction_edit_mode', 10)->default('lock')->after('allow_edit_posted_transactions');
        });
    }

    public function down(): void
    {
        Schema::table('company_accounting_settings', function (Blueprint $table) {
            $table->dropColumn('transaction_edit_mode');
        });
    }
};
