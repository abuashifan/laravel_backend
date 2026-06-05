<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_accounting_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_accounting_settings', 'block_outside_current_fiscal_year')) {
                $table->boolean('block_outside_current_fiscal_year')->default(true)->after('user_permission_mode');
            }

            if (! Schema::hasColumn('company_accounting_settings', 'date_warning_enabled')) {
                $table->boolean('date_warning_enabled')->default(true)->after('block_outside_current_fiscal_year');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_accounting_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_accounting_settings', 'date_warning_enabled')) {
                $table->dropColumn('date_warning_enabled');
            }

            if (Schema::hasColumn('company_accounting_settings', 'block_outside_current_fiscal_year')) {
                $table->dropColumn('block_outside_current_fiscal_year');
            }
        });
    }
};

