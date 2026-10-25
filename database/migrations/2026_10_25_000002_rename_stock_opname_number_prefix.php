<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock Opname dulu berawalan "SO", sama dengan Sales Order, sehingga bisa
 * ada dua dokumen "SO-2026-000001". Awalan bawaan opname kini "OP"; setting
 * perusahaan yang masih memakai bawaan lama ikut dipindahkan. Awalan yang
 * sudah diubah sendiri oleh user tidak disentuh. Dokumen lama tetap
 * memakai nomornya.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('document_numbering_settings')) {
            return;
        }

        DB::table('document_numbering_settings')
            ->where('document_type', 'stock_opname')
            ->where('prefix', 'SO')
            ->update(['prefix' => 'OP', 'updated_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('document_numbering_settings')) {
            return;
        }

        DB::table('document_numbering_settings')
            ->where('document_type', 'stock_opname')
            ->where('prefix', 'OP')
            ->update(['prefix' => 'SO', 'updated_at' => now()]);
    }
};
