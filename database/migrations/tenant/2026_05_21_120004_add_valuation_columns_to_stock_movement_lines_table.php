<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movement_lines', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_movement_lines', 'quantity_before')) {
                $table->decimal('quantity_before', 18, 4)->default(0)->after('quantity');
            }
            if (! Schema::hasColumn('stock_movement_lines', 'quantity_after')) {
                $table->decimal('quantity_after', 18, 4)->default(0)->after('quantity_before');
            }
            if (! Schema::hasColumn('stock_movement_lines', 'average_cost_before')) {
                $table->decimal('average_cost_before', 18, 6)->default(0)->after('quantity_after');
            }
            if (! Schema::hasColumn('stock_movement_lines', 'average_cost_after')) {
                $table->decimal('average_cost_after', 18, 6)->default(0)->after('average_cost_before');
            }
            if (! Schema::hasColumn('stock_movement_lines', 'value_before')) {
                $table->decimal('value_before', 18, 2)->default(0)->after('average_cost_after');
            }
            if (! Schema::hasColumn('stock_movement_lines', 'value_after')) {
                $table->decimal('value_after', 18, 2)->default(0)->after('value_before');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_movement_lines', function (Blueprint $table) {
            foreach ([
                'quantity_before',
                'quantity_after',
                'average_cost_before',
                'average_cost_after',
                'value_before',
                'value_after',
            ] as $col) {
                if (Schema::hasColumn('stock_movement_lines', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

