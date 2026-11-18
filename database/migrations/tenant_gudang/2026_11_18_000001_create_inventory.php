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
        $schema->create('gudang_items', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name', 160);
            $table->string('unit', 24);
        });
        $schema->create('gudang_warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
        });
        $schema->create('gudang_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained('gudang_warehouses');
            $table->string('name', 100);
            $table->unique(['warehouse_id', 'name']);
        });
        $db = DB::connection($this->connection);
        $warehouse = $db->table('gudang_warehouses')->insertGetId(['name' => 'Gudang Utama']);
        $db->table('gudang_locations')->insert(['warehouse_id' => $warehouse, 'name' => 'Umum']);
        $schema->create('gudang_stock_documents', function (Blueprint $table) {
            $table->id(); // Nomor penerimaan atomik per tenant.
            $table->uuid('public_id')->unique();
            $table->string('type', 40);
            $table->string('request_key', 80)->unique();
            $table->char('payload_hash', 64);
            $table->unsignedBigInteger('user_id');
            $table->string('source', 160);
            $table->timestampTz('posted_at');
        });
        $schema->create('gudang_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('gudang_stock_documents');
            $table->foreignId('item_id')->constrained('gudang_items');
            $table->foreignId('location_id')->constrained('gudang_locations');
            $table->bigInteger('quantity_milli');
            $table->index(['item_id', 'location_id', 'id']);
        });
        $schema->create('gudang_stock_balances', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained('gudang_items');
            $table->foreignId('location_id')->constrained('gudang_locations');
            $table->bigInteger('quantity_milli')->default(0);
            $table->primary(['item_id', 'location_id']);
        });
    }

    public function down(): void
    {
        foreach (['stock_balances', 'stock_movements', 'stock_documents', 'locations', 'warehouses', 'items'] as $table) {
            Schema::connection($this->connection)->dropIfExists('gudang_'.$table);
        }
    }
};
