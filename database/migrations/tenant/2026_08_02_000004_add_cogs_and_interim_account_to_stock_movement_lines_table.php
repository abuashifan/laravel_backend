<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movement_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movement_lines', 'cogs_account_id')) {
                $table->foreignId('cogs_account_id')->nullable()->after('inventory_account_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_movement_lines', 'inventory_interim_account_id')) {
                $table->foreignId('inventory_interim_account_id')->nullable()->after('cogs_account_id')->constrained('chart_of_accounts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movement_lines', function (Blueprint $table) {
            foreach (['cogs_account_id', 'inventory_interim_account_id'] as $column) {
                if (Schema::hasColumn('stock_movement_lines', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
        });
    }
};
