<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'gudang';

    public function up(): void
    {
        $schema = Schema::connection('gudang');
        $schema->table('gudang_items', function (Blueprint $t) {
            $t->string('category', 100)->nullable();
            $t->bigInteger('minimum_milli')->default(0);
        });
        $schema->create('gudang_item_photos', function (Blueprint $t) {
            $t->foreignId('item_id')->primary()->constrained('gudang_items');
            $t->text('data_url');
        });
        $schema->create('gudang_barcodes', function (Blueprint $t) {
            $t->string('code', 100)->primary();
            $t->foreignId('item_id')->constrained('gudang_items');
        });
        $schema->table('gudang_locations', function (Blueprint $t) {
            $t->foreignId('parent_id')->nullable()->constrained('gudang_locations');
            $t->string('kind', 20)->default('simpan');
            $t->string('barcode', 100)->nullable()->unique();
        });
        $schema->create('gudang_members', function (Blueprint $t) {
            $t->foreignId('warehouse_id')->constrained('gudang_warehouses');
            $t->unsignedBigInteger('user_id');
            $t->primary(['user_id', 'warehouse_id']);
        });
        $schema->create('gudang_parties', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 20);
            $t->string('name', 160);
            $t->unique(['kind', 'name']);
        });
        $schema->table('gudang_stock_documents', function (Blueprint $t) {
            $t->string('status', 20)->default('posted')->index();
            $t->foreignId('location_id')->nullable()->constrained('gudang_locations');
            $t->foreignId('target_id')->nullable()->constrained('gudang_locations');
            $t->foreignId('parent_id')->nullable()->constrained('gudang_stock_documents');
            $t->date('document_date')->nullable();
        });
        $schema->create('gudang_doc_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('gudang_stock_documents');
            $t->foreignId('item_id')->constrained('gudang_items');
            $t->bigInteger('quantity_milli');
            $t->bigInteger('received_milli')->default(0);
            $t->unique(['document_id', 'item_id']);
        });
        $schema->create('gudang_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained('gudang_stock_documents');
            $t->unsignedBigInteger('user_id');
            $t->string('action', 30);
            $t->string('request_key', 100)->unique();
            $t->char('payload_hash', 64);
            $t->text('note')->nullable();
            $t->timestampTz('created_at');
        });
        $schema->table('gudang_stock_movements', function (Blueprint $t) {
            $t->timestampTz('occurred_at')->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('gudang');
        $schema->table('gudang_stock_movements', fn (Blueprint $t) => $t->dropColumn('occurred_at'));
        $schema->dropIfExists('gudang_actions');
        $schema->dropIfExists('gudang_doc_lines');
        $schema->table('gudang_stock_documents', function (Blueprint $t) {
            foreach (['location_id', 'target_id', 'parent_id'] as $column) {
                $t->dropConstrainedForeignId($column);
            }
            $t->dropIndex(['status']);
            $t->dropColumn(['status', 'document_date']);
        });
        $schema->dropIfExists('gudang_parties');
        $schema->dropIfExists('gudang_members');
        $schema->table('gudang_locations', function (Blueprint $t) {
            $t->dropConstrainedForeignId('parent_id');
            $t->dropUnique(['barcode']);
            $t->dropColumn(['kind', 'barcode']);
        });
        $schema->dropIfExists('gudang_barcodes');
        $schema->dropIfExists('gudang_item_photos');
        $schema->table('gudang_items', fn (Blueprint $t) => $t->dropColumn(['category', 'minimum_milli']));
    }
};
