<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_receipt_lines', 'vendor_payment_lines', 'sales_invoices', 'vendor_bills'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->decimal('settlement_discount_amount', 18, 2)->default(0));
        }
        foreach (['sales_receipt_lines', 'vendor_payment_lines'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->foreignId('settlement_discount_account_id')->nullable()->constrained('chart_of_accounts');
                $table->string('settlement_discount_note', 240)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sales_receipt_lines', 'vendor_payment_lines'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropConstrainedForeignId('settlement_discount_account_id');
                $table->dropColumn('settlement_discount_note');
            });
        }
        foreach (['sales_receipt_lines', 'vendor_payment_lines', 'sales_invoices', 'vendor_bills'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('settlement_discount_amount'));
        }
    }
};
