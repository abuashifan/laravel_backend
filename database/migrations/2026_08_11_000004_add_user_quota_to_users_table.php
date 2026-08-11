<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jumlah user per perusahaan untuk tier Custom, sejajar `company_quota`.
 *
 * Tier bertingkat memakai `plans.max_users`; hanya tier Custom yang membaca
 * kolom ini. NULL berarti ikut angka paket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedInteger('user_quota')->nullable()->after('company_quota');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('user_quota');
        });
    }
};
