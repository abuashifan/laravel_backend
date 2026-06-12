<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('sales_invoices', function (Blueprint $table) {
            $table->index('customer_id', 'sales_invoices_customer_id_lookup_index');
        });

        Schema::connection('tenant')->table('vendor_bills', function (Blueprint $table) {
            $table->index('vendor_id', 'vendor_bills_vendor_id_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('vendor_bills', function (Blueprint $table) {
            $table->dropIndex('vendor_bills_vendor_id_lookup_index');
        });

        Schema::connection('tenant')->table('sales_invoices', function (Blueprint $table) {
            $table->dropIndex('sales_invoices_customer_id_lookup_index');
        });
    }
};
