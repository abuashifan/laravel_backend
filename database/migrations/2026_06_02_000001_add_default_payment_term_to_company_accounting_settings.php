<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_accounting_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('company_accounting_settings', 'default_payment_term_id')) {
                $table->unsignedBigInteger('default_payment_term_id')->nullable()->after('base_currency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_accounting_settings', function (Blueprint $table) {
            if (Schema::hasColumn('company_accounting_settings', 'default_payment_term_id')) {
                $table->dropColumn('default_payment_term_id');
            }
        });
    }
};
