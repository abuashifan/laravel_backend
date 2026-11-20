<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'gudang';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->table('gudang_batch_balances', fn (Blueprint $table) => $table->bigInteger('reserved_milli')->default(0));
        $schema->table('gudang_locations', function (Blueprint $table) {
            $table->unsignedInteger('sku_slots')->nullable();
            $table->string('preferred_category', 100)->nullable();
        });
        $schema->table('gudang_outbound_lines', function (Blueprint $table) {
            $table->string('input_unit', 24)->nullable();
            $table->bigInteger('input_quantity_milli')->nullable();
            $table->bigInteger('factor_milli')->default(1000);
        });
        $schema->create('gudang_outbound_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('gudang_outbound_orders');
            $table->foreignId('item_id')->constrained('gudang_items');
            $table->foreignId('location_id')->constrained('gudang_locations');
            $table->foreignId('batch_id')->nullable()->constrained('gudang_batches');
            $table->bigInteger('quantity_milli');
            $table->bigInteger('shipped_milli')->default(0);
            $table->index(['order_id', 'id']);
        });
        $schema->create('gudang_outbound_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('gudang_outbound_orders');
            $table->string('status', 20);
            $table->timestampTz('created_at');
            $table->index(['order_id', 'id']);
        });
        $schema->create('gudang_outbound_picks', function (Blueprint $table) {
            $table->foreignId('run_id')->constrained('gudang_outbound_runs');
            $table->foreignId('allocation_id')->constrained('gudang_outbound_allocations');
            $table->bigInteger('planned_milli');
            $table->bigInteger('picked_milli');
            $table->string('reason', 300)->nullable();
            $table->string('location_code', 100)->nullable();
            $table->string('item_code', 100)->nullable();
            $table->string('batch_code', 100)->nullable();
            $table->primary(['run_id', 'allocation_id']);
        });
        $schema->create('gudang_outbound_packages', function (Blueprint $table) {
            $table->foreignId('run_id')->constrained('gudang_outbound_runs');
            $table->unsignedSmallInteger('number');
            $table->unsignedInteger('weight_grams');
            $table->string('note', 300)->nullable();
            $table->primary(['run_id', 'number']);
        });
        $schema->create('gudang_outbound_ship_docs', function (Blueprint $table) {
            $table->foreignId('run_id')->constrained('gudang_outbound_runs');
            $table->foreignId('document_id')->unique()->constrained('gudang_stock_documents');
            $table->primary(['run_id', 'document_id']);
        });
        $schema->table('gudang_outbound_actions', function (Blueprint $table) {
            $table->foreignId('run_id')->nullable()->constrained('gudang_outbound_runs');
        });
        $db = DB::connection($this->connection);
        $db->table('gudang_outbound_allocations')->insertUsing(
            ['order_id', 'item_id', 'location_id', 'quantity_milli', 'shipped_milli'],
            $db->table('gudang_outbound_lines as line')
                ->join('gudang_outbound_orders as o', 'o.id', '=', 'line.order_id')
                ->select('line.order_id', 'line.item_id', 'o.location_id', 'line.quantity_milli', 'line.shipped_milli')
        );
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->table('gudang_outbound_actions', fn (Blueprint $table) => $table->dropConstrainedForeignId('run_id'));
        foreach (['ship_docs', 'packages', 'picks', 'runs', 'allocations'] as $table) {
            $schema->dropIfExists('gudang_outbound_'.$table);
        }
        $schema->table('gudang_outbound_lines', fn (Blueprint $table) => $table->dropColumn(['input_unit', 'input_quantity_milli', 'factor_milli']));
        $schema->table('gudang_locations', fn (Blueprint $table) => $table->dropColumn(['sku_slots', 'preferred_category']));
        $schema->table('gudang_batch_balances', fn (Blueprint $table) => $table->dropColumn('reserved_milli'));
    }
};
