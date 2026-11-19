<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'gudang';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->table('gudang_items', function (Blueprint $table) {
            $table->boolean('batch_enabled')->default(false);
            $table->string('abc_override', 1)->nullable();
            $table->unsignedInteger('lead_days')->default(7);
            $table->bigInteger('safety_milli')->default(0);
        });
        $schema->create('gudang_item_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('gudang_items');
            $table->string('name', 24);
            $table->bigInteger('factor_milli');
            $table->unique(['item_id', 'name']);
        });
        $schema->create('gudang_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('gudang_items');
            $table->string('code', 100);
            $table->date('expires_at')->nullable()->index();
            $table->string('status', 20)->default('active');
            $table->unique(['item_id', 'code']);
        });
        $schema->create('gudang_batch_balances', function (Blueprint $table) {
            $table->foreignId('batch_id')->constrained('gudang_batches');
            $table->foreignId('location_id')->constrained('gudang_locations');
            $table->bigInteger('quantity_milli')->default(0);
            $table->primary(['batch_id', 'location_id']);
        });
        $schema->create('gudang_movement_batches', function (Blueprint $table) {
            $table->foreignId('movement_id')->constrained('gudang_stock_movements');
            $table->foreignId('batch_id')->constrained('gudang_batches');
            $table->bigInteger('quantity_milli');
            $table->primary(['movement_id', 'batch_id']);
        });
        $schema->table('gudang_doc_lines', function (Blueprint $table) {
            $table->string('input_unit', 24)->nullable();
            $table->bigInteger('input_quantity_milli')->nullable();
            $table->bigInteger('factor_milli')->default(1000);
            $table->foreignId('batch_id')->nullable()->constrained('gudang_batches');
        });
        $schema->table('gudang_stock_movements', function (Blueprint $table) {
            $table->string('input_unit', 24)->nullable();
            $table->bigInteger('input_quantity_milli')->nullable();
            $table->bigInteger('factor_milli')->default(1000);
        });
        $schema->create('gudang_stock_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('gudang_items');
            $table->foreignId('warehouse_id')->constrained('gudang_warehouses');
            $table->bigInteger('minimum_milli')->default(0);
            $table->bigInteger('max_milli')->default(0);
            $table->bigInteger('reorder_milli')->default(0);
            $table->unique(['item_id', 'warehouse_id']);
        });
        $schema->create('gudang_batch_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('gudang_batches');
            $table->unsignedBigInteger('user_id');
            $table->string('status', 20);
            $table->string('note', 500);
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('gudang_batch_actions');
        $schema->dropIfExists('gudang_stock_rules');
        $schema->table('gudang_stock_movements', fn (Blueprint $table) => $table->dropColumn(['input_unit', 'input_quantity_milli', 'factor_milli']));
        $schema->table('gudang_doc_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('batch_id');
            $table->dropColumn(['input_unit', 'input_quantity_milli', 'factor_milli']);
        });
        $schema->dropIfExists('gudang_movement_batches');
        $schema->dropIfExists('gudang_batch_balances');
        $schema->dropIfExists('gudang_batches');
        $schema->dropIfExists('gudang_item_units');
        $schema->table('gudang_items', fn (Blueprint $table) => $table->dropColumn(['batch_enabled', 'abc_override', 'lead_days', 'safety_milli']));
    }
};
