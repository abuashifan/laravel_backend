<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            if (! Schema::hasColumn('vendor_bills', 'ap_account_id')) {
                $table->foreignId('ap_account_id')
                    ->nullable()
                    ->after('vendor_id')
                    ->constrained('chart_of_accounts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendor_bills', function (Blueprint $table) {
            if (Schema::hasColumn('vendor_bills', 'ap_account_id')) {
                $table->dropConstrainedForeignId('ap_account_id');
            }
        });
    }
};
