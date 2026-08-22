<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_return_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_return_lines', 'sales_return_account_id')) {
                $table->foreignId('sales_return_account_id')->nullable()->after('product_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
        });

        Schema::table('purchase_return_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_return_lines', 'purchase_return_account_id')) {
                $table->foreignId('purchase_return_account_id')->nullable()->after('product_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('sales_invoice_lines', 'sales_discount_account_id')) {
                $table->foreignId('sales_discount_account_id')->nullable()->after('revenue_account_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('sales_return_lines', 'sales_return_account_id')) {
                $table->dropConstrainedForeignId('sales_return_account_id');
            }
        });

        Schema::table('purchase_return_lines', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_return_lines', 'purchase_return_account_id')) {
                $table->dropConstrainedForeignId('purchase_return_account_id');
            }
        });

        Schema::table('sales_invoice_lines', function (Blueprint $table) {
            if (Schema::hasColumn('sales_invoice_lines', 'sales_discount_account_id')) {
                $table->dropConstrainedForeignId('sales_discount_account_id');
            }
        });
    }
};
