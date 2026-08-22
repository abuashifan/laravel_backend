<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_orders' => 'customer_address', 'purchase_orders' => 'vendor_address'] as $tableName => $after) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName, $after) {
                if (! Schema::hasColumn($tableName, 'payment_term_id')) {
                    $table->unsignedBigInteger('payment_term_id')->nullable()->after($after);
                    $table->index('payment_term_id');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['purchase_orders', 'sales_orders'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'payment_term_id')) {
                    $table->dropIndex($tableName.'_payment_term_id_index');
                    $table->dropColumn('payment_term_id');
                }
            });
        }
    }
};
