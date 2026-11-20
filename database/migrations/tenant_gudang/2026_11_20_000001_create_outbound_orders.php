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
        $schema->table('gudang_stock_balances', function (Blueprint $table) {
            $table->bigInteger('reserved_milli')->default(0);
        });
        $schema->create('gudang_outbound_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('location_id')->constrained('gudang_locations');
            $table->string('destination', 160);
            $table->string('status', 20)->default('reserved');
            $table->string('request_key', 80)->unique();
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('created_at');
            $table->index(['location_id', 'id']);
        });
        $schema->create('gudang_outbound_lines', function (Blueprint $table) {
            $table->foreignId('order_id')->constrained('gudang_outbound_orders');
            $table->foreignId('item_id')->constrained('gudang_items');
            $table->string('unit', 24);
            $table->bigInteger('quantity_milli');
            $table->bigInteger('shipped_milli')->default(0);
            $table->primary(['order_id', 'item_id']);
        });
        $schema->create('gudang_outbound_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('gudang_outbound_orders');
            $table->foreignId('document_id')->nullable()->constrained('gudang_stock_documents');
            $table->string('request_key', 80);
            $table->char('payload_hash', 64);
            $table->string('action', 20);
            $table->string('note', 500)->nullable();
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('created_at');
            $table->unique(['order_id', 'request_key']);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('gudang_outbound_actions');
        $schema->dropIfExists('gudang_outbound_lines');
        $schema->dropIfExists('gudang_outbound_orders');
        $schema->table('gudang_stock_balances', fn (Blueprint $table) => $table->dropColumn('reserved_milli'));
    }
};
