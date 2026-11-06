<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aplikasi asal undangan. `pos` = dibuat dari aplikasi kasir, jadi tautan
 * email mengarah ke aplikasi kasir. Null = Finlite Accounting (perilaku lama).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->string('product', 30)->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('company_invitations', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
