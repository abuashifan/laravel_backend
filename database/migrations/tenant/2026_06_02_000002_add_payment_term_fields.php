<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['contacts', 'sales_invoices', 'vendor_bills'] as $tableName) {
            $after = $this->afterColumn($tableName);
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
        foreach (['vendor_bills', 'sales_invoices', 'contacts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (Schema::hasColumn($tableName, 'payment_term_id')) {
                    $table->dropIndex($tableName.'_payment_term_id_index');
                    $table->dropColumn('payment_term_id');
                }
            });
        }
    }

    private function afterColumn(string $tableName): string
    {
        return match ($tableName) {
            'contacts' => 'contact_type',
            'sales_invoices' => 'due_date',
            'vendor_bills' => 'due_date',
            default => 'id',
        };
    }
};
