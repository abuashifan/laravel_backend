<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('company_accounting_settings', 'user_permission_mode')) {
            return;
        }

        Schema::table('company_accounting_settings', function (Blueprint $table) {
            $table->string('user_permission_mode')->default('role_template')->after('tax_enabled');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_accounting_settings', 'user_permission_mode')) {
            return;
        }

        Schema::table('company_accounting_settings', function (Blueprint $table) {
            $table->dropColumn('user_permission_mode');
        });
    }
};

