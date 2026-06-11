<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_invoice_lines', 'revenue_account_id')) {
                $table->foreignId('revenue_account_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('chart_of_accounts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            if (Schema::hasColumn('sales_invoice_lines', 'revenue_account_id')) {
                $table->dropConstrainedForeignId('revenue_account_id');
            }
        });
    }
};
