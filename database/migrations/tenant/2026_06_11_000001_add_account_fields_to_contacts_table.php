<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('contacts', 'receivable_account_id')) {
                $table->foreignId('receivable_account_id')
                    ->nullable()
                    ->after('payment_term_id')
                    ->constrained('chart_of_accounts')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('contacts', 'payable_account_id')) {
                $table->foreignId('payable_account_id')
                    ->nullable()
                    ->after('receivable_account_id')
                    ->constrained('chart_of_accounts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            if (Schema::hasColumn('contacts', 'payable_account_id')) {
                $table->dropConstrainedForeignId('payable_account_id');
            }

            if (Schema::hasColumn('contacts', 'receivable_account_id')) {
                $table->dropConstrainedForeignId('receivable_account_id');
            }
        });
    }
};
