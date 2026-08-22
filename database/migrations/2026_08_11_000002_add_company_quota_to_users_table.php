<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuota perusahaan khusus per client, menimpa angka dari paket.
 *
 * Paket tetap jadi default, tapi jumlah perusahaan sering merupakan kesepakatan
 * per client — bukan sesuatu yang harus dipaksa lewat pembuatan paket baru.
 * NULL berarti "ikut paket".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('company_quota')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('company_quota');
        });
    }
};
