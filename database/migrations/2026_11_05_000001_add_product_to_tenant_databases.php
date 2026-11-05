<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap P ekosistem: satu perusahaan bisa punya beberapa tenant, satu per
 * produk (accounting, pos, ...). Tenant lama otomatis menjadi `accounting`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->string('product', 30)->default('accounting')->after('company_id');
        });

        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->dropUnique(['company_id']);
            $table->unique(['company_id', 'product']);
            $table->index('product');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'product']);
            $table->dropIndex(['product']);
            $table->unique(['company_id']);
        });

        Schema::table('tenant_databases', function (Blueprint $table) {
            $table->dropColumn('product');
        });
    }
};
