<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movement_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_movement_id');
            $table->string('movement_type', 50);
            $table->string('direction', 10);
            $table->unsignedBigInteger('product_id');
            $table->string('product_code')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 6)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);
            $table->decimal('average_cost_before', 18, 6)->nullable();
            $table->decimal('average_cost_after', 18, 6)->nullable();
            $table->decimal('quantity_before', 18, 4)->nullable();
            $table->decimal('quantity_after', 18, 4)->nullable();
            $table->decimal('value_before', 18, 2)->nullable();
            $table->decimal('value_after', 18, 2)->nullable();
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('stock_movement_id')->references('id')->on('stock_movements')->cascadeOnDelete();

            $table->index('product_id');
            $table->index('warehouse_id');
            $table->index('stock_movement_id');
            $table->index(['source_line_type', 'source_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movement_lines');
    }
};

