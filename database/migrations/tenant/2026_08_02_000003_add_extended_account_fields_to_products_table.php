<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'sales_discount_account_id')) {
                $table->foreignId('sales_discount_account_id')->nullable()->after('sales_account_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('products', 'sales_return_account_id')) {
                $table->foreignId('sales_return_account_id')->nullable()->after('sales_discount_account_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('products', 'purchase_return_account_id')) {
                $table->foreignId('purchase_return_account_id')->nullable()->after('sales_return_account_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('products', 'inventory_interim_account_id')) {
                $table->foreignId('inventory_interim_account_id')->nullable()->after('inventory_account_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['sales_discount_account_id', 'sales_return_account_id', 'purchase_return_account_id', 'inventory_interim_account_id'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
        });
    }
};
