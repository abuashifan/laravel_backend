<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('stock_adjustment_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('unit_id')->nullable();
            $table->string('adjustment_type', 20);
            $table->decimal('quantity', 18, 4);
            $table->decimal('unit_cost', 18, 6)->nullable();
            $table->decimal('total_cost', 18, 2)->nullable();
            $table->decimal('system_quantity_before', 18, 4)->nullable();
            $table->decimal('system_value_before', 18, 2)->nullable();
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('stock_adjustment_id')->references('id')->on('stock_adjustments')->cascadeOnDelete();
            $table->index('product_id');
            $table->index('warehouse_id');
            $table->index('adjustment_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
    }
};

