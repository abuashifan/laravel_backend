<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_receipt_lines') && Schema::hasColumn('sales_receipt_lines', 'billing_invoice_id')) {
            // SQLite menolak DROP COLUMN bila kolomnya masih dipakai index —
            // buang indexnya lebih dulu (idempoten via IF EXISTS).
            DB::statement('DROP INDEX IF EXISTS sales_receipt_lines_billing_invoice_id_index');
            Schema::table('sales_receipt_lines', function (Blueprint $table) {
                $table->dropColumn('billing_invoice_id');
            });
        }

        if (Schema::hasTable('sales_receipts') && Schema::hasColumn('sales_receipts', 'billing_invoice_id')) {
            DB::statement('DROP INDEX IF EXISTS sales_receipts_billing_invoice_id_index');
            Schema::table('sales_receipts', function (Blueprint $table) {
                $table->dropColumn('billing_invoice_id');
            });
        }

        Schema::dropIfExists('billing_invoice_lines');
        Schema::dropIfExists('billing_invoices');
    }

    public function down(): void
    {
        if (Schema::hasTable('sales_receipts') && ! Schema::hasColumn('sales_receipts', 'billing_invoice_id')) {
            Schema::table('sales_receipts', function (Blueprint $table) {
                $table->unsignedBigInteger('billing_invoice_id')->nullable()->after('sales_invoice_id');
                $table->index('billing_invoice_id');
            });
        }

        if (Schema::hasTable('sales_receipt_lines') && ! Schema::hasColumn('sales_receipt_lines', 'billing_invoice_id')) {
            Schema::table('sales_receipt_lines', function (Blueprint $table) {
                $table->unsignedBigInteger('billing_invoice_id')->nullable()->after('sales_invoice_id');
                $table->index('billing_invoice_id');
            });
        }
    }
};
