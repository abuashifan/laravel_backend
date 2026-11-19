<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'gudang';

    public function up(): void
    {
        $s = Schema::connection('gudang');
        $s->create('gudang_counts', function (Blueprint $t) {
            $t->id();
            $t->uuid('public_id')->unique();
            $t->string('status', 20)->index();
            $t->string('mode', 20);
            $t->string('request_key', 80)->unique();
            $t->char('payload_hash', 64);
            $t->unsignedBigInteger('user_id');
            $t->timestampTz('cutoff_at');
            $t->timestampTz('created_at');
            $t->timestampTz('submitted_at')->nullable();
            $t->timestampTz('completed_at')->nullable();
            $t->foreignId('document_id')->nullable()->constrained('gudang_stock_documents');
        });
        $s->create('gudang_count_locations', function (Blueprint $t) {
            $t->foreignId('count_id')->constrained('gudang_counts');
            $t->foreignId('location_id')->constrained('gudang_locations');
            $t->primary(['count_id', 'location_id']);
        });
        $s->create('gudang_count_claims', function (Blueprint $t) {
            $t->foreignId('location_id')->primary()->constrained('gudang_locations');
            $t->foreignId('count_id')->constrained('gudang_counts');
        });
        $s->create('gudang_count_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('count_id')->constrained('gudang_counts');
            $t->foreignId('item_id')->constrained('gudang_items');
            $t->foreignId('location_id')->constrained('gudang_locations');
            $t->foreignId('batch_id')->nullable()->constrained('gudang_batches');
            $t->bigInteger('snapshot_milli');
            $t->bigInteger('counted_milli')->nullable();
            $t->bigInteger('recount_milli')->nullable();
            $t->unsignedBigInteger('counter_id')->nullable();
            $t->unsignedBigInteger('recount_by')->nullable();
            $t->index(['count_id', 'location_id']);
        });
        $s->create('gudang_count_actions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('count_id')->constrained('gudang_counts');
            $t->unsignedBigInteger('user_id');
            $t->string('action', 30);
            $t->string('request_key', 80)->unique();
            $t->char('payload_hash', 64);
            $t->text('note')->nullable();
            $t->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        $s = Schema::connection('gudang');
        foreach (['gudang_count_actions', 'gudang_count_lines', 'gudang_count_claims', 'gudang_count_locations', 'gudang_counts'] as $name) {
            $s->dropIfExists($name);
        }
    }
};
