<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('opname_number')->unique();
            $table->date('opname_date');
            $table->unsignedBigInteger('warehouse_id');
            $table->string('status', 30)->default('draft');
            $table->timestamp('counted_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->unsignedBigInteger('counted_by')->nullable();
            $table->unsignedBigInteger('finalized_by')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('opname_date');
            $table->index('warehouse_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opnames');
    }
};

